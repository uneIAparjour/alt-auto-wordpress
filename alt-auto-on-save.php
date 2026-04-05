<?php
/**
 * Plugin Name:  Alt Auto On Save — uneIAparjour
 * Description:  Écrit automatiquement les textes alt des images, vidéos et audios
 *               à chaque publication ou mise à jour d'un article.
 *               Ne touche jamais un alt déjà renseigné.
 * Version:      1.5
 * Author:       Bertrand Formet & Claude (Anthropic)
 * License:      MIT
 *
 * Changelog v1.5 :
 *  - #2 : is_user_logged_in() + current_user_can() → WP-CLI/cron non bloqués.
 *  - #3 : core/media-text gère mediaType='audio' (WP 6.3+).
 *  - #4/#5 : shortcodes [video src] et [video mp4] traités dans collect_from_html.
 *  - #1 : regex (\?.*)? supprimé (wp_parse_url retire déjà le query string).
 *  - #6 : distinction .ogv (vidéo) vs .ogg (audio) documentée.
 *
 * Changelog v1.4 :
 *  - #10 : basename() → wp_parse_url() dans les helpers (query strings).
 *  - #12 : garde $depth (max 50) contre la récursion infinie.
 *  - #6  : current_user_can('edit_post') ajouté.
 *  - #2  : docblock altauto_collect_from_html() corrigé.
 *  - #5  : array_unique() sur $image_ids (doublons galerie WP < 5.9).
 *  - #14 : altauto_is_capture() / altauto_is_recording() clarifiés.
 *  - #1  : save_post_post documenté (intentionnel pour uneIAparjour).
 *  - #3  : _wp_attachment_image_alt sur vidéo/audio documenté.
 *
 * Changelog v1.3 :
 *  - Prise en charge des audios : core/audio, <audio>, <source> audio, [audio].
 *
 * Changelog v1.2 :
 *  - core/video sans attrs → fallback innerHTML.
 *  - core/cover backgroundType=video.
 *  - array_unique, method_exists, cache URL→ID.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Déclencheur ─────────────────────────────────────────────────────────────
// save_post_post : intentionnellement limité au CPT « post ».
// uneIAparjour ne publie que des articles — pas de pages ni de CPT tiers.
// Pour couvrir d'autres types, remplacer par add_action('save_post', ...).

add_action( 'save_post_post', 'altauto_on_save', 20, 3 );

function altauto_on_save( int $post_id, WP_Post $post, bool $update ): void {

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) )                return;
    if ( 'publish' !== $post->post_status )               return;

    // Capacité : conditionné à is_user_logged_in() pour ne pas bloquer
    // WP-CLI et wp-cron qui n'ont pas de user courant par défaut (#2).
    if ( is_user_logged_in() && ! current_user_can( 'edit_post', $post_id ) ) return;

    $title   = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
    $content = $post->post_content;

    $n_capture       = 1;
    $n_image         = 1;
    $n_capture_video = 1;
    $n_video         = 1;
    $n_recording     = 1;
    $n_audio         = 1;

    $seen_image_ids = [];
    $seen_video_ids = [];
    $seen_audio_ids = [];

    // Cache URL→ID local (évite N appels SQL attachment_url_to_postid identiques)
    $url_id_cache = [];

    // Note : _wp_attachment_image_alt est écrit sur les attachments vidéo et audio.
    // WordPress ne l'injecte pas nativement dans <video>/<audio>, mais Yoast SEO et
    // RankMath le lisent pour le référencement. C'est un choix délibéré.

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
    $audio_ids  = [];
    $audio_srcs = [];

    altauto_collect_from_blocks(
        parse_blocks( $content ),
        $image_ids, $video_ids, $video_srcs,
        $audio_ids, $audio_srcs
    );

    // ── 3. Images de contenu ──────────────────────────────────────────────────
    // array_unique() élimine les doublons : galeries WP < 5.9 ($attrs['ids'])
    // + innerBlocks core/image de la même galerie (récursion).
    foreach ( array_unique( $image_ids ) as $id ) {
        $id = (int) $id;
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

    // ── 5. Vidéos par URL (HTML brut, blocs tiers, shortcodes) ───────────────
    foreach ( array_unique( $video_srcs ) as $src_url ) {
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

    // ── 6. Audios (IDs directs depuis parse_blocks) ───────────────────────────
    foreach ( $audio_ids as $id ) {
        if ( ! $id || isset( $seen_audio_ids[ $id ] ) ) continue;
        $seen_audio_ids[ $id ] = true;

        $url   = wp_get_attachment_url( $id ) ?: '';
        $label = altauto_is_recording( $url )
            ? 'enregistrement audio ' . $n_recording++
            : 'audio '                . $n_audio++;
        altauto_write_alt( $id, $title . ' / ' . $label );
    }

    // ── 7. Audios par URL (HTML brut, shortcodes [audio]) ────────────────────
    foreach ( array_unique( $audio_srcs ) as $src_url ) {
        if ( array_key_exists( $src_url, $url_id_cache ) ) {
            $id = $url_id_cache[ $src_url ];
        } else {
            $id = attachment_url_to_postid( $src_url ) ?: null;
            $url_id_cache[ $src_url ] = $id;
        }
        if ( ! $id || isset( $seen_audio_ids[ $id ] ) ) continue;
        $seen_audio_ids[ $id ] = true;

        $label = altauto_is_recording( $src_url )
            ? 'enregistrement audio ' . $n_recording++
            : 'audio '                . $n_audio++;
        altauto_write_alt( $id, $title . ' / ' . $label );
    }
}

// ─── Extraction via parse_blocks() ───────────────────────────────────────────

/**
 * Parcourt récursivement l'arbre de blocs et collecte les médias sans DB.
 * La résolution URL→ID est faite dans altauto_on_save(), pas ici.
 *
 * @param array    $blocks
 * @param int[]    &$image_ids   IDs images
 * @param int[]    &$video_ids   IDs vidéos (résolution directe via attrs['id'])
 * @param string[] &$video_srcs  URLs vidéos (résolution par attachment_url_to_postid)
 * @param int[]    &$audio_ids   IDs audios (résolution directe via attrs['id'])
 * @param string[] &$audio_srcs  URLs audios (résolution par attachment_url_to_postid)
 * @param int      $depth        Profondeur de récursion (garde : max 50)
 */
function altauto_collect_from_blocks(
    array  $blocks,
    array  &$image_ids,
    array  &$video_ids,
    array  &$video_srcs,
    array  &$audio_ids,
    array  &$audio_srcs,
    int    $depth = 0
): void {
    // Garde contre la récursion infinie (blocs malformés, filtres tiers)
    if ( $depth > 50 ) return;

    foreach ( $blocks as $block ) {
        $name  = $block['blockName'] ?? '';
        $attrs = $block['attrs']     ?? [];
        $inner = $block['innerHTML'] ?? '';

        switch ( $name ) {

            case 'core/image':
                if ( ! empty( $attrs['id'] ) ) {
                    $image_ids[] = (int) $attrs['id'];
                } elseif ( $inner ) {
                    altauto_collect_from_html( $inner, $image_ids, $video_srcs, $audio_srcs );
                }
                break;

            // Cover : image ou vidéo de fond selon backgroundType
            case 'core/cover':
                if ( ! empty( $attrs['id'] ) ) {
                    if ( 'video' === ( $attrs['backgroundType'] ?? '' ) ) {
                        $video_ids[] = (int) $attrs['id'];
                    } else {
                        $image_ids[] = (int) $attrs['id'];
                    }
                }
                break;

            // Galerie WP < 5.9 : ids dans attrs.
            // WP 5.9+ : images dans innerBlocks core/image (récursion ci-dessous).
            // array_unique() dans altauto_on_save() protège contre les doublons.
            case 'core/gallery':
                if ( ! empty( $attrs['ids'] ) ) {
                    foreach ( $attrs['ids'] as $gid ) {
                        $image_ids[] = (int) $gid;
                    }
                }
                break;

            // Media-text : image, vidéo ou audio selon mediaType.
            // mediaType='audio' disponible depuis WP 6.3 (#3).
            case 'core/media-text':
                if ( ! empty( $attrs['mediaId'] ) ) {
                    $mtype = $attrs['mediaType'] ?? 'image';
                    if ( 'video' === $mtype ) {
                        $video_ids[] = (int) $attrs['mediaId'];
                    } elseif ( 'audio' === $mtype ) {
                        $audio_ids[] = (int) $attrs['mediaId'];
                    } else {
                        $image_ids[] = (int) $attrs['mediaId'];
                    }
                }
                break;

            // Vidéo : id > src > innerHTML
            case 'core/video':
                if ( ! empty( $attrs['id'] ) ) {
                    $video_ids[] = (int) $attrs['id'];
                } elseif ( ! empty( $attrs['src'] ) ) {
                    $video_srcs[] = $attrs['src'];
                } elseif ( $inner ) {
                    altauto_collect_from_html( $inner, $image_ids, $video_srcs, $audio_srcs );
                }
                break;

            // Audio : même logique que core/video
            case 'core/audio':
                if ( ! empty( $attrs['id'] ) ) {
                    $audio_ids[] = (int) $attrs['id'];
                } elseif ( ! empty( $attrs['src'] ) ) {
                    $audio_srcs[] = $attrs['src'];
                } elseif ( $inner ) {
                    altauto_collect_from_html( $inner, $image_ids, $video_srcs, $audio_srcs );
                }
                break;

            // Blocs non reconnus (tiers, Classic Editor) : parser le HTML brut
            default:
                if ( $inner ) {
                    altauto_collect_from_html( $inner, $image_ids, $video_srcs, $audio_srcs );
                }
                break;
        }

        // Récursion sur les blocs enfants (groupes, colonnes, galeries, etc.)
        if ( ! empty( $block['innerBlocks'] ) ) {
            altauto_collect_from_blocks(
                $block['innerBlocks'],
                $image_ids, $video_ids, $video_srcs,
                $audio_ids, $audio_srcs,
                $depth + 1
            );
        }
    }
}

/**
 * Extrait IDs images, URLs vidéos et URLs audios depuis du HTML brut.
 * Aucune requête DB — résolution dans altauto_on_save().
 *
 * Note : cette fonction ne reçoit pas &$video_ids ni &$audio_ids car le HTML
 * brut ne contient pas d'attributs data-id fiables pour ces types. Seules les
 * URLs (src) sont collectées ici ; la résolution ID se fait en aval.
 *
 * Note : les images sans classe wp-image-* ni data-id sont ignorées
 * (coût de attachment_url_to_postid trop élevé en save_post pour ce cas marginal).
 *
 * @param string   $html
 * @param int[]    &$image_ids
 * @param string[] &$video_srcs
 * @param string[] &$audio_srcs
 */
function altauto_collect_from_html(
    string $html,
    array  &$image_ids,
    array  &$video_srcs,
    array  &$audio_srcs
): void {

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
        }

        if ( $has_tag_closers ) {
            // ── Passe unique avec tag_closers (WP 6.4+) ───────────────────────
            // $in_video / $in_audio : profondeur par type.
            // <source> attribuée au bon parent ; celles dans <picture> ignorées.
            $p2       = new WP_HTML_Tag_Processor( $html );
            $in_video = 0;
            $in_audio = 0;
            while ( $p2->next_tag( [ 'tag_closers' => 'visit' ] ) ) {
                $tag    = $p2->get_tag();
                $closer = $p2->is_tag_closer();

                if ( 'VIDEO' === $tag ) {
                    if ( $closer ) {
                        $in_video = max( 0, $in_video - 1 );
                    } else {
                        $in_video++;
                        $src = (string) ( $p2->get_attribute( 'src' ) ?? '' );
                        if ( $src ) $video_srcs[] = $src;
                    }
                } elseif ( 'AUDIO' === $tag ) {
                    if ( $closer ) {
                        $in_audio = max( 0, $in_audio - 1 );
                    } else {
                        $in_audio++;
                        $src = (string) ( $p2->get_attribute( 'src' ) ?? '' );
                        if ( $src ) $audio_srcs[] = $src;
                    }
                } elseif ( 'SOURCE' === $tag && ! $closer ) {
                    $src  = (string) ( $p2->get_attribute( 'src' )  ?? '' );
                    $type = (string) ( $p2->get_attribute( 'type' ) ?? '' );
                    if ( $src ) {
                        if ( $in_video > 0 && altauto_is_video_source( $src, $type ) ) {
                            $video_srcs[] = $src;
                        } elseif ( $in_audio > 0 && altauto_is_audio_source( $src, $type ) ) {
                            $audio_srcs[] = $src;
                        }
                    }
                }
            }
        } else {
            // ── Trois passes séparées (sans is_tag_closer) ────────────────────
            // Limitation : <source> non filtrées par parent. Filtre MIME réduit
            // les faux positifs mais ne les élimine pas (.ogg ambigu).
            $p3 = new WP_HTML_Tag_Processor( $html );
            while ( $p3->next_tag( 'video' ) ) {
                $src = (string) ( $p3->get_attribute( 'src' ) ?? '' );
                if ( $src ) $video_srcs[] = $src;
            }
            $p4 = new WP_HTML_Tag_Processor( $html );
            while ( $p4->next_tag( 'audio' ) ) {
                $src = (string) ( $p4->get_attribute( 'src' ) ?? '' );
                if ( $src ) $audio_srcs[] = $src;
            }
            $p5 = new WP_HTML_Tag_Processor( $html );
            while ( $p5->next_tag( 'source' ) ) {
                $src  = (string) ( $p5->get_attribute( 'src' )  ?? '' );
                $type = (string) ( $p5->get_attribute( 'type' ) ?? '' );
                if ( ! $src ) continue;
                if ( altauto_is_video_source( $src, $type ) ) {
                    $video_srcs[] = $src;
                } elseif ( altauto_is_audio_source( $src, $type ) ) {
                    $audio_srcs[] = $src;
                }
            }
        }

    } else {
        // ── Fallback regex (WP < 6.2) ─────────────────────────────────────────
        preg_match_all( '/<img[^>]+>/i', $html, $imgs );
        foreach ( $imgs[0] as $tag ) {
            if ( preg_match( '/\bwp-image-(\d+)\b/i', $tag, $m ) ) {
                $image_ids[] = (int) $m[1];
            } elseif ( preg_match( '/\bdata-id=["\'](\d+)["\']/i', $tag, $m ) ) {
                $image_ids[] = (int) $m[1];
            }
        }
        preg_match_all(
            '/\bsrc=["\']([^"\']+\.(?:mp4|webm|ogv|mov|m4v|avi|mkv))["\']/i',
            $html, $vsrcs
        );
        foreach ( $vsrcs[1] as $src ) $video_srcs[] = $src;

        preg_match_all(
            '/\bsrc=["\']([^"\']+\.(?:mp3|ogg|wav|flac|aac|m4a|opus|wma))["\']/i',
            $html, $asrcs
        );
        foreach ( $asrcs[1] as $src ) $audio_srcs[] = $src;
    }

    // Shortcodes [audio] et [video] dans innerHTML de blocs non reconnus (#4/#5)
    if ( str_contains( $html, '[audio' ) ) {
        preg_match_all( '/\[audio\b[^\]]*\bsrc=["\']([^"\']+)["\']/i', $html, $sc_a );
        foreach ( $sc_a[1] as $src ) $audio_srcs[] = $src;
    }
    if ( str_contains( $html, '[video' ) ) {
        // src="…" — format standard
        preg_match_all( '/\[video\b[^\]]*\bsrc=["\']([^"\']+)["\']/i', $html, $sc_vs );
        foreach ( $sc_vs[1] as $src ) $video_srcs[] = $src;
        // mp4="…" — variante WordPress native
        preg_match_all( '/\[video\b[^\]]*\bmp4=["\']([^"\']+)["\']/i', $html, $sc_vm );
        foreach ( $sc_vm[1] as $src ) $video_srcs[] = $src;
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Extrait le nom de fichier depuis une URL, sans query string (#10).
 * basename('file.mp4?v=2') → 'file.mp4'
 * Retourne '' si l'URL est vide (#7 — les appelants testent la valeur retournée).
 */
function altauto_filename( string $url ): string {
    if ( '' === $url ) return '';
    $path = wp_parse_url( $url, PHP_URL_PATH ) ?: $url;
    return strtolower( urldecode( basename( $path ) ) );
}

/**
 * True si le fichier correspond à une capture d'écran ou vidéo.
 * Mots-clés : « capture », « enregistrement », « screenrecording ».
 *
 * Note : altauto_is_recording() partage « enregistrement » et « capture ».
 * Un audio nommé « capture-voix.mp3 » sera labellisé « enregistrement audio ».
 * Ce chevauchement est documenté et accepté (classification sémantique probable).
 */
function altauto_is_capture( string $url ): bool {
    $name = altauto_filename( $url );
    return str_contains( $name, 'capture' )
        || str_contains( $name, 'enregistrement' )
        || str_contains( $name, 'screenrecording' );
}

/**
 * True si le fichier correspond à un enregistrement audio.
 * Mots-clés : « enregistrement », « recording », « capture ».
 * Utilisé exclusivement pour les audios.
 */
function altauto_is_recording( string $url ): bool {
    $name = altauto_filename( $url );
    return str_contains( $name, 'enregistrement' )
        || str_contains( $name, 'recording' )
        || str_contains( $name, 'capture' );
}

/**
 * True si src/type correspond à une source vidéo.
 *
 * .ogv (Ogg Video / Theora) est classé vidéo.
 * .ogg est absent : classé audio par altauto_is_audio_source() par convention
 * (Vorbis dominant en pratique ; Theora utilise généralement .ogv).
 * Le regex n'inclut plus (\?.*)? : wp_parse_url retire déjà le query string (#1).
 */
function altauto_is_video_source( string $src, string $type ): bool {
    if ( str_starts_with( $type, 'video/' ) ) return true;
    if ( str_starts_with( $type, 'audio/' ) ) return false;
    $path = wp_parse_url( $src, PHP_URL_PATH ) ?: $src;
    return (bool) preg_match( '/\.(mp4|webm|ogv|mov|m4v|avi|mkv)$/i', $path );
}

/**
 * True si src/type correspond à une source audio.
 *
 * .ogg est classé audio par convention (Vorbis dominant ; Theora utilise .ogv).
 */
function altauto_is_audio_source( string $src, string $type ): bool {
    if ( str_starts_with( $type, 'audio/' ) ) return true;
    if ( str_starts_with( $type, 'video/' ) ) return false;
    $path = wp_parse_url( $src, PHP_URL_PATH ) ?: $src;
    return (bool) preg_match( '/\.(mp3|ogg|wav|flac|aac|m4a|opus|wma)$/i', $path );
}

/**
 * Écrit le meta alt uniquement si le champ est vide.
 * Utilisé pour images, vidéos et audios (voir note dans altauto_on_save).
 */
function altauto_write_alt( int $id, string $alt ): void {
    if ( '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) return;
    update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
}
