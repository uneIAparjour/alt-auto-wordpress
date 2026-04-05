<?php
/**
 * Plugin Name:  Alt Auto On Save — uneIAparjour
 * Description:  Écrit automatiquement les textes alt des images et vidéos
 *               à chaque publication ou mise à jour d'un article.
 *               Ne touche jamais un alt déjà renseigné.
 * Version:      1.2
 * Author:       uneIAparjour
 * License:      GPL-2.0
 *
 * Changelog v1.2 :
 *  - Bug #6 : core/video sans attrs → fallback sur innerHTML.
 *  - Bug #7 : core/cover avec backgroundType=video → redirigé vers $video_ids.
 *  - Fix #2 : array_unique() sur $video_srcs avant résolution (évite N appels DB doublons).
 *  - Fix #5 : détection tag_closers via method_exists('WP_HTML_Tag_Processor','is_tag_closer')
 *             plutôt que version_compare($GLOBALS['wp_version']).
 *  - Fix #4 documenté : images HTML brutes sans classe ni data-id sont ignorées (choix perf).
 *  - Cache local attachment_url_to_postid (variable statique dans altauto_on_save).
 *  - Commentaire core/gallery corrigé (déprécation depuis WP 5.9, pas 6.3).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Déclencheur ─────────────────────────────────────────────────────────────

add_action( 'save_post_post', 'altauto_on_save', 20, 3 );

function altauto_on_save( int $post_id, WP_Post $post, bool $update ): void {

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) )                return;
    if ( 'publish' !== $post->post_status )               return;

    $title   = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
    $content = $post->post_content;

    $n_capture       = 1;
    $n_image         = 1;
    $n_capture_video = 1;
    $n_video         = 1;

    $seen_image_ids = [];
    $seen_video_ids = [];

    // Cache local pour attachment_url_to_postid (évite N requêtes SQL sur la même URL)
    $url_id_cache = [];

    // ── 1. Image à la une ─────────────────────────────────────────────────────
    $featured_id = (int) get_post_thumbnail_id( $post_id );
    if ( $featured_id ) {
        $url   = wp_get_attachment_url( $featured_id ) ?: '';
        $label = altauto_is_capture( $url )
            ? 'capture ' . $n_capture++
            : 'image '   . $n_image++;
        altauto_write_alt( $featured_id, $title . ' / ' . $label );
        $seen_image_ids[ $featured_id ] = true;
    }

    // ── 2. Extraction via parse_blocks() ─────────────────────────────────────
    $image_ids  = [];
    $video_ids  = [];
    $video_srcs = [];

    altauto_collect_from_blocks(
        parse_blocks( $content ),
        $image_ids,
        $video_ids,
        $video_srcs
    );

    // ── 3. Images de contenu ──────────────────────────────────────────────────
    foreach ( $image_ids as $id ) {
        if ( ! $id || isset( $seen_image_ids[ $id ] ) ) continue;
        $seen_image_ids[ $id ] = true;

        $url   = wp_get_attachment_url( $id ) ?: '';
        $label = altauto_is_capture( $url )
            ? 'capture ' . $n_capture++
            : 'image '   . $n_image++;
        altauto_write_alt( $id, $title . ' / ' . $label );
    }

    // ── 4. Vidéos (IDs directs depuis parse_blocks) ───────────────────────────
    foreach ( $video_ids as $id ) {
        if ( ! $id || isset( $seen_video_ids[ $id ] ) ) continue;
        $seen_video_ids[ $id ] = true;

        $url   = wp_get_attachment_url( $id ) ?: '';
        $label = altauto_is_capture( $url )
            ? 'capture vidéo ' . $n_capture_video++
            : 'vidéo '         . $n_video++;
        altauto_write_alt( $id, $title . ' / ' . $label );
    }

    // ── 5. Vidéos détectées par URL (HTML brut, blocs tiers, shortcodes) ──────
    // Déduplique les URLs avant résolution pour éviter N requêtes SQL identiques.
    foreach ( array_unique( $video_srcs ) as $src_url ) {
        // Cache local : même URL déjà résolue = pas de seconde requête
        if ( array_key_exists( $src_url, $url_id_cache ) ) {
            $id = $url_id_cache[ $src_url ];
        } else {
            $id = attachment_url_to_postid( $src_url ) ?: null;
            $url_id_cache[ $src_url ] = $id;
        }

        if ( ! $id || isset( $seen_video_ids[ $id ] ) ) continue;
        $seen_video_ids[ $id ] = true;

        $label = altauto_is_capture( $src_url )
            ? 'capture vidéo ' . $n_capture_video++
            : 'vidéo '         . $n_video++;
        altauto_write_alt( $id, $title . ' / ' . $label );
    }
}

// ─── Extraction via parse_blocks() ───────────────────────────────────────────

/**
 * Parcourt récursivement l'arbre de blocs et collecte les médias sans DB.
 *
 * @param array    $blocks
 * @param int[]    &$image_ids   Accumule les IDs d'images
 * @param int[]    &$video_ids   Accumule les IDs de vidéos (résolution directe)
 * @param string[] &$video_srcs  Accumule les URLs de vidéos (résolution par URL)
 */
function altauto_collect_from_blocks(
    array  $blocks,
    array  &$image_ids,
    array  &$video_ids,
    array  &$video_srcs
): void {
    foreach ( $blocks as $block ) {
        $name  = $block['blockName'] ?? '';
        $attrs = $block['attrs']     ?? [];
        $inner = $block['innerHTML'] ?? '';

        switch ( $name ) {

            // ── Images simples ────────────────────────────────────────────────
            case 'core/image':
                if ( ! empty( $attrs['id'] ) ) {
                    $image_ids[] = (int) $attrs['id'];
                } elseif ( $inner ) {
                    // Fallback : image insérée sans ID dans les attrs (cas rare)
                    altauto_collect_from_html( $inner, $image_ids, $video_srcs );
                }
                break;

            // ── Cover : image ou vidéo de fond selon backgroundType ───────────
            case 'core/cover':
                if ( ! empty( $attrs['id'] ) ) {
                    if ( 'video' === ( $attrs['backgroundType'] ?? '' ) ) {
                        $video_ids[] = (int) $attrs['id'];
                    } else {
                        $image_ids[] = (int) $attrs['id'];
                    }
                }
                break;

            // ── Galerie ───────────────────────────────────────────────────────
            // Depuis WP 5.9, les images sont dans innerBlocks (core/image).
            // $attrs['ids'] est l'ancien format (WP < 5.9) — on le garde en fallback.
            case 'core/gallery':
                if ( ! empty( $attrs['ids'] ) ) {
                    foreach ( $attrs['ids'] as $gid ) {
                        $image_ids[] = (int) $gid;
                    }
                }
                // Les innerBlocks sont parcourus en récursion ci-dessous.
                break;

            // ── Media-text : image ou vidéo selon mediaType ───────────────────
            case 'core/media-text':
                if ( ! empty( $attrs['mediaId'] ) ) {
                    if ( 'video' === ( $attrs['mediaType'] ?? 'image' ) ) {
                        $video_ids[] = (int) $attrs['mediaId'];
                    } else {
                        $image_ids[] = (int) $attrs['mediaId'];
                    }
                }
                break;

            // ── Vidéo native ──────────────────────────────────────────────────
            // Ordre de priorité : id > src dans attrs > innerHTML.
            // Cas #6 corrigé : si attrs vides, le innerHTML contient souvent
            // la balise <video src="…"> (vidéo insérée par glisser-déposer).
            case 'core/video':
                if ( ! empty( $attrs['id'] ) ) {
                    $video_ids[] = (int) $attrs['id'];
                } elseif ( ! empty( $attrs['src'] ) ) {
                    $video_srcs[] = $attrs['src'];
                } elseif ( $inner ) {
                    altauto_collect_from_html( $inner, $image_ids, $video_srcs );
                }
                break;

            // ── Blocs non reconnus : parser le HTML brut ──────────────────────
            // Couvre les blocs tiers, le Classic Editor, et les blocs core
            // non explicitement listés ci-dessus.
            default:
                if ( $inner ) {
                    altauto_collect_from_html( $inner, $image_ids, $video_srcs );
                }
                break;
        }

        // Récursion : groupes, colonnes, blocs composites, innerBlocks galerie
        if ( ! empty( $block['innerBlocks'] ) ) {
            altauto_collect_from_blocks(
                $block['innerBlocks'],
                $image_ids,
                $video_ids,
                $video_srcs
            );
        }
    }
}

/**
 * Extrait IDs images et URLs vidéos depuis du HTML brut.
 * Aucune requête DB — la résolution est dans altauto_on_save.
 *
 * Note : les images sans classe wp-image-* ni data-id sont silencieusement
 * ignorées (choix délibéré : coût perf de attachment_url_to_postid trop élevé
 * en save_post pour un cas marginal).
 */
function altauto_collect_from_html(
    string $html,
    array  &$image_ids,
    array  &$video_srcs
): void {

    // Détection feature par method_exists — plus fiable que version_compare($GLOBALS['wp_version'])
    $has_tag_closers = class_exists( 'WP_HTML_Tag_Processor' )
        && method_exists( 'WP_HTML_Tag_Processor', 'is_tag_closer' );

    if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {

        // ── Images ───────────────────────────────────────────────────────────
        $p = new WP_HTML_Tag_Processor( $html );
        while ( $p->next_tag( 'img' ) ) {
            $cls = (string) ( $p->get_attribute( 'class' ) ?? '' );
            $did = $p->get_attribute( 'data-id' );
            if ( preg_match( '/\bwp-image-(\d+)\b/i', $cls, $m ) ) {
                $image_ids[] = (int) $m[1];
            } elseif ( $did ) {
                $image_ids[] = (int) $did;
            }
            // Images sans classe ni data-id : ignorées (voir note ci-dessus)
        }

        // ── Vidéos ───────────────────────────────────────────────────────────
        if ( $has_tag_closers ) {
            // Passe unique avec is_tag_closer() + compteur de profondeur.
            // Les <source> hors <video> (audio, picture) sont ignorées.
            $p2       = new WP_HTML_Tag_Processor( $html );
            $in_video = 0;
            while ( $p2->next_tag( [ 'tag_closers' => 'visit' ] ) ) {
                $tag = $p2->get_tag();
                if ( 'VIDEO' === $tag ) {
                    if ( $p2->is_tag_closer() ) {
                        $in_video = max( 0, $in_video - 1 );
                    } else {
                        $in_video++;
                        $src = (string) ( $p2->get_attribute( 'src' ) ?? '' );
                        if ( $src ) $video_srcs[] = $src;
                    }
                } elseif ( 'SOURCE' === $tag && $in_video > 0 && ! $p2->is_tag_closer() ) {
                    $src  = (string) ( $p2->get_attribute( 'src' )  ?? '' );
                    $type = (string) ( $p2->get_attribute( 'type' ) ?? '' );
                    if ( $src && altauto_is_video_source( $src, $type ) ) {
                        $video_srcs[] = $src;
                    }
                }
            }
        } else {
            // Deux passes séparées (WP sans is_tag_closer).
            // Limitation connue : les <source> dans <audio> pourraient être captées
            // si l'extension correspond à une vidéo (edge case très marginal).
            $p3 = new WP_HTML_Tag_Processor( $html );
            while ( $p3->next_tag( 'video' ) ) {
                $src = (string) ( $p3->get_attribute( 'src' ) ?? '' );
                if ( $src ) $video_srcs[] = $src;
            }
            $p4 = new WP_HTML_Tag_Processor( $html );
            while ( $p4->next_tag( 'source' ) ) {
                $src  = (string) ( $p4->get_attribute( 'src' )  ?? '' );
                $type = (string) ( $p4->get_attribute( 'type' ) ?? '' );
                if ( $src && altauto_is_video_source( $src, $type ) ) {
                    $video_srcs[] = $src;
                }
            }
        }

    } else {
        // ── Fallback regex (WP < 6.2) ─────────────────────────────────────
        preg_match_all( '/<img[^>]+>/i', $html, $imgs );
        foreach ( $imgs[0] as $tag ) {
            if ( preg_match( '/\bwp-image-(\d+)\b/i', $tag, $m ) ) {
                $image_ids[] = (int) $m[1];
            } elseif ( preg_match( '/\bdata-id=["\'](\d+)["\']/i', $tag, $m ) ) {
                $image_ids[] = (int) $m[1];
            }
        }
        // Capture src sur <video> ET <source> avec filtre extension
        preg_match_all(
            '/\bsrc=["\']([^"\']+\.(?:mp4|webm|ogv|mov|m4v|avi|mkv))["\']/i',
            $html,
            $vsrcs
        );
        foreach ( $vsrcs[1] as $src ) {
            $video_srcs[] = $src;
        }
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * True si le src/type correspond à une source vidéo (pas audio, pas image).
 */
function altauto_is_video_source( string $src, string $type ): bool {
    if ( str_starts_with( $type, 'video/' ) ) return true;
    if ( str_starts_with( $type, 'audio/' ) ) return false;
    return (bool) preg_match(
        '/\.(mp4|webm|ogv|mov|m4v|avi|mkv)(\?.*)?$/i',
        $src
    );
}

/**
 * True si le nom de fichier correspond à un enregistrement d'écran.
 */
function altauto_is_capture( string $url ): bool {
    $name = strtolower( urldecode( basename( $url ) ) );
    return str_contains( $name, 'capture' )
        || str_contains( $name, 'enregistrement' )
        || str_contains( $name, 'screenrecording' );
}

/**
 * Écrit le meta alt uniquement si le champ est vide.
 */
function altauto_write_alt( int $id, string $alt ): void {
    if ( '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) return;
    update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
}
