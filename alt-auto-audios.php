<?php
/**
 * Plugin Name:  Alt Auto Audios — uneIAparjour
 * Description:  Génère les textes alt des fichiers audio hébergés dans la médiathèque.
 *               Mode diagnostic : inspecte les articles sans rien écrire.
 *               Mode traitement : écrit les alt après validation.
 * Version:      1.2
 * Author:       Bertrand Formet & Claude (Anthropic)
 * License:      MIT
 *
 * Changelog v1.2 :
 *  - Bug #1 : audios_written++ conditionné à $id non null en mode diagnostic.
 *  - UX  #2 : statut 'no_id' pour les audios détectés mais non résolvables → ⚠️ dans le log.
 *
 * Changelog v1.1 :
 *  - #6  : alta_update() vérifie skip_existing en dry_run → le diagnostic reflète fidèlement
 *           ce qui serait ignoré (⏭️) vs écrit (🔍).
 *  - #15 : compteurs audios_written / audios_skipped séparés côté PHP et JS ;
 *          le résumé final indique "X mis à jour, Y ignorés".
 *  - #13 : gestion des erreurs HTTP côté JS (r.ok check avant r.json()).
 *  - #1  : garde current_user_can() en tête de alta_render_page().
 *  - #2  : sanitize_text_field() sur les $_POST string (diag_only, skip_existing).
 *  - #3  : @set_time_limit remplacé par function_exists() + set_time_limit().
 *  - #4  : commentaire documentant l'usage de _wp_attachment_image_alt sur un audio.
 *  - #7  : <source> filtrée par type MIME audio strict ; commentaire sur la limite WP < 6.4.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ALTA_VERSION', '1.2' );

// ─── Menu ─────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_management_page(
        'Alt Auto Audios — uneIAparjour',
        'Alt Auto Audios',
        'manage_options',
        'alt-auto-audios',
        'alta_render_page'
    );
} );

// ─── Page d'administration ────────────────────────────────────────────────────

function alta_render_page(): void {
    // Garde défensive (#1) — au cas où la fonction serait appelée hors contexte
    if ( ! current_user_can( 'manage_options' ) ) return;

    $total = (int) wp_count_posts( 'post' )->publish;
    ?>
    <div class="wrap">
        <h1>🎵 Alt Auto Audios
            <span style="font-size:13px;font-weight:normal;color:#666;">
                — uneIAparjour v<?php echo esc_html( ALTA_VERSION ); ?>
            </span>
        </h1>

        <div class="card" style="max-width:760px;padding:20px 24px;margin-top:16px;">
            <p style="margin-top:0;">
                Parcourt tous les articles publiés et renseigne le champ <code>alt</code>
                des fichiers audio hébergés dans la médiathèque.
            </p>
            <p style="color:#666;font-size:12px;margin:0 0 14px;">
                ℹ️ Le champ <code>_wp_attachment_image_alt</code> est utilisé pour les audios
                par souci de cohérence avec les images et vidéos. Il est lu par Yoast / RankMath
                pour le référencement mais n'est pas affiché nativement dans l'UI médias pour
                les audios — c'est un choix délibéré de ce plugin.
            </p>
            <table style="border-collapse:collapse;font-size:13px;margin-bottom:14px;">
                <tr style="background:#f6f7f7;">
                    <th style="padding:5px 10px;text-align:left;border:1px solid #ddd;">Nom de fichier contient…</th>
                    <th style="padding:5px 10px;text-align:left;border:1px solid #ddd;">Alt généré</th>
                </tr>
                <tr>
                    <td style="padding:5px 10px;border:1px solid #ddd;">« enregistrement », « recording » ou « capture »</td>
                    <td style="padding:5px 10px;border:1px solid #ddd;font-family:monospace;">Titre / enregistrement audio 1</td>
                </tr>
                <tr style="background:#f6f7f7;">
                    <td style="padding:5px 10px;border:1px solid #ddd;">autre</td>
                    <td style="padding:5px 10px;border:1px solid #ddd;font-family:monospace;">Titre / audio 1</td>
                </tr>
            </table>
            <p>📦 <strong><?php echo esc_html( number_format( $total ) ); ?> articles publiés</strong></p>
            <hr>
            <p>
                <label>
                    <input type="checkbox" id="alta-skip" checked>
                    Ne pas écraser les audios qui ont déjà un texte alt
                </label>
            </p>
            <p style="display:flex;gap:12px;flex-wrap:wrap;">
                <button id="alta-diag-btn" class="button button-secondary button-hero">
                    🔍 Mode diagnostic (aucune écriture)
                </button>
                <button id="alta-run-btn" class="button button-primary button-hero">
                    🚀 Écrire les alt
                </button>
            </p>
        </div>

        <div id="alta-progress" style="display:none;max-width:760px;margin-top:20px;">
            <div style="background:#e0e0e0;border-radius:6px;height:14px;overflow:hidden;margin-bottom:10px;">
                <div id="alta-bar"
                     style="background:#2271b1;height:14px;width:0%;transition:width .4s ease;border-radius:6px;">
                </div>
            </div>
            <p id="alta-status" style="color:#555;font-style:italic;">Initialisation…</p>
        </div>

        <div id="alta-log-wrap" style="display:none;max-width:760px;margin-top:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <strong id="alta-log-title" style="font-size:13px;"></strong>
                <button id="alta-copy-btn" class="button button-small">📋 Copier le log</button>
            </div>
            <div id="alta-log"
                 style="max-height:440px;overflow-y:auto;background:#fff;border:1px solid #ddd;
                        border-radius:4px;padding:12px 16px;font-family:monospace;font-size:12px;line-height:1.8;">
            </div>
        </div>

        <div id="alta-done" style="display:none;max-width:760px;margin-top:16px;">
            <div class="notice notice-success" style="padding:12px 16px;">
                <p id="alta-summary" style="margin:0;font-size:14px;"></p>
            </div>
        </div>
    </div>

    <script>
    /* global ajaxurl */
    (function () {
        const diagBtn  = document.getElementById('alta-diag-btn');
        const runBtn   = document.getElementById('alta-run-btn');
        const prog     = document.getElementById('alta-progress');
        const bar      = document.getElementById('alta-bar');
        const status   = document.getElementById('alta-status');
        const logWrap  = document.getElementById('alta-log-wrap');
        const logTitle = document.getElementById('alta-log-title');
        const logEl    = document.getElementById('alta-log');
        const copyBtn  = document.getElementById('alta-copy-btn');
        const doneBox  = document.getElementById('alta-done');
        const summary  = document.getElementById('alta-summary');
        const skip     = document.getElementById('alta-skip');

        let nonce = <?php echo wp_json_encode( wp_create_nonce( 'alta_nonce' ) ); ?>;

        // Compteurs séparés : écrits / ignorés / hors médiathèque (#15, #2)
        let totalWritten  = 0;
        let totalSkipped  = 0;
        let totalNoId     = 0;
        let totalArticles = 0;

        copyBtn.addEventListener('click', () => {
            const lines = Array.from( logEl.querySelectorAll('div') )
                .map(d => d.textContent).join('\n');
            navigator.clipboard.writeText(lines).then(() => {
                copyBtn.textContent = '✅ Copié !';
                setTimeout(() => { copyBtn.textContent = '📋 Copier le log'; }, 2000);
            });
        });

        function reset() {
            logEl.innerHTML       = '';
            doneBox.style.display = 'none';
            prog.style.display    = 'block';
            logWrap.style.display = 'block';
            bar.style.background  = '#2271b1';
            bar.style.width       = '0%';
            totalWritten          = 0;
            totalSkipped          = 0;
            totalNoId             = 0;
            totalArticles         = 0;
        }

        function disableButtons() { diagBtn.disabled = true; runBtn.disabled = true; }
        function enableButtons()  { diagBtn.disabled = false; runBtn.disabled = false; }

        function onError(msg) {
            status.textContent = '❌ ' + msg;
            enableButtons();
        }

        diagBtn.addEventListener('click', () => {
            if (!confirm('Lancer le diagnostic sur tous les articles ?')) return;
            disableButtons(); reset();
            logTitle.textContent = '🔍 Diagnostic — aucune écriture';
            run(0, true);
        });

        runBtn.addEventListener('click', () => {
            if (!confirm('Écrire les alt sur tous les audios trouvés ?')) return;
            disableButtons(); reset();
            logTitle.textContent = '🚀 Traitement en cours';
            run(0, false);
        });

        function run(offset, diagOnly) {
            fetch(ajaxurl, {
                method : 'POST',
                body   : new URLSearchParams({
                    action        : 'alta_process',
                    nonce         : nonce,
                    offset        : offset,
                    diag_only     : diagOnly ? '1' : '0',
                    skip_existing : skip.checked ? '1' : '0',
                }),
            })
            // #13 : vérification HTTP avant parsing JSON
            .then(r => {
                if (!r.ok) throw new Error(`Erreur serveur HTTP ${r.status}`);
                return r.json();
            })
            .then(({ success, data }) => {
                if (!success) { onError(data ?? 'Erreur inconnue'); return; }

                if (data.new_nonce) nonce = data.new_nonce;

                // #15 / #2 : compteurs séparés
                totalWritten  += data.audios_written;
                totalSkipped  += data.audios_skipped;
                totalNoId     += data.audios_no_id ?? 0;
                totalArticles += data.posts_processed;

                const totalFound = totalWritten + totalSkipped + totalNoId;

                /* Log structuré — XSS-safe */
                data.log.forEach(entry => {
                    const line = document.createElement('div');
                    line.style.borderBottom  = '1px solid #f0f0f0';
                    line.style.paddingBottom = '4px';
                    line.style.marginBottom  = '4px';

                    const title    = document.createElement('div');
                    const titleIco = document.createElement('span');
                    titleIco.textContent = entry.audios.length > 0 ? '📄 ' : '⚪ ';
                    const titleEm  = document.createElement('strong');
                    titleEm.textContent = entry.title;
                    title.appendChild(titleIco);
                    title.appendChild(titleEm);
                    line.appendChild(title);

                    if (entry.audios.length === 0) {
                        const none = document.createElement('div');
                        none.style.color       = '#999';
                        none.style.paddingLeft = '20px';
                        none.textContent       = '  (aucun audio détecté)';
                        line.appendChild(none);
                    }

                    entry.audios.forEach(a => {
                        const aline = document.createElement('div');
                        aline.style.paddingLeft = '20px';

                        const ico = document.createElement('span');
                        ico.textContent = a.written  ? '  ✅ '
                                        : a.skipped  ? '  ⏭️ '
                                        : a.no_id    ? '  ⚠️ '  // hors médiathèque
                                        :              '  🔍 ';

                        const info = document.createElement('span');
                        info.style.fontFamily = 'monospace';
                        info.textContent      = a.alt_preview;

                        const meta = document.createElement('span');
                        meta.style.color    = '#888';
                        meta.style.fontSize = '11px';
                        meta.textContent    = '  ← ' + a.filename;

                        aline.appendChild(ico);
                        aline.appendChild(info);
                        aline.appendChild(meta);
                        line.appendChild(aline);
                    });

                    logEl.appendChild(line);
                });
                logEl.scrollTop = logEl.scrollHeight;

                const pct = data.total > 0
                    ? Math.min(100, Math.round((data.offset / data.total) * 100))
                    : 100;
                bar.style.width    = pct + '%';
                status.textContent = `Article ${data.offset} / ${data.total} `
                    + `— ${totalFound} audio(s) détecté(s)`;

                if (data.done) {
                    bar.style.background  = diagOnly ? '#f0a500' : '#00a32a';
                    bar.style.width       = '100%';
                    enableButtons();

                    // #15 : résumé précis selon le mode
                    if (diagOnly) {
                        status.textContent    = `🔍 Diagnostic terminé.`;
                        const wouldWrite  = totalWritten;
                        const wouldSkip   = totalSkipped;
                        doneBox.style.display = 'block';
                        summary.textContent   =
                            `Diagnostic : ${totalFound} audio(s) trouvé(s) sur ${totalArticles} articles. `
                            + `${wouldWrite} seraient écrits, ${wouldSkip} ignorés (alt existant)`
                            + (totalNoId ? `, ${totalNoId} hors médiathèque ⚠️` : '') + `.`
                            + (wouldWrite > 0 ? ' → Cliquez « Écrire les alt » pour les traiter.' : '');
                        logTitle.textContent  = `🔍 Diagnostic — ${totalFound} audio(s) trouvé(s)`;
                    } else {
                        status.textContent    = `✅ Traitement terminé.`;
                        doneBox.style.display = 'block';
                        summary.textContent   =
                            `✅ ${totalArticles} articles parcourus — `
                            + `${totalWritten} audio(s) mis à jour, ${totalSkipped} ignoré(s)`
                            + (totalNoId ? `, ${totalNoId} hors médiathèque ⚠️` : '') + `.`;
                        logTitle.textContent  =
                            `✅ ${totalWritten} audio(s) mis à jour, ${totalSkipped} ignoré(s)`;
                    }
                } else {
                    run(data.offset, diagOnly);
                }
            })
            .catch(err => onError(err.message));
        }
    })();
    </script>
    <?php
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** True si le nom de fichier correspond à un enregistrement. */
function alta_is_recording( string $url ): bool {
    $name = strtolower( urldecode( basename( $url ) ) );
    return str_contains( $name, 'enregistrement' )
        || str_contains( $name, 'recording' )
        || str_contains( $name, 'capture' );
}

/**
 * Tente d'écrire le meta alt.
 *
 * Note : _wp_attachment_image_alt est le meta standard pour les images, utilisé
 * ici sur les audios par cohérence avec les autres plugins de cette suite.
 * Il est lu par Yoast/RankMath pour le SEO. Il n'est pas affiché dans l'UI
 * médias WordPress pour les audios, et n'est pas restitué nativement dans
 * le HTML <audio> — ce sont des limitations connues et acceptées.
 *
 * @return string 'written' | 'skipped' | 'found' (dry_run sans alt existant)
 */
function alta_update( int $id, string $alt, bool $dry_run, bool $skip_existing ): string {
    // #6 : skip_existing vérifié AVANT dry_run → le diagnostic reflète fidèlement
    // ce qui serait ignoré (⏭️) vs ce qui serait écrit (🔍).
    if ( $skip_existing
         && '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
        return 'skipped';
    }
    if ( $dry_run ) return 'found';
    update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
    return 'written';
}

/**
 * Cherche tous les audios dans le contenu d'un article.
 * 3 couches : blocs Gutenberg → balises HTML → shortcodes.
 * Aucune requête DB dans cette fonction (sauf attachment_url_to_postid inévitable).
 *
 * @return list<array{id: int|null, src: string}>
 */
function alta_find_audios( string $content ): array {
    $items = [];
    $seen  = [];

    /* ── 1. Blocs Gutenberg <!-- wp:audio {"id":NNN} --> ── */
    preg_match_all( '/<!--\s*wp:audio\s*(\{[^}]*\})\s*-->/i', $content, $gut );
    foreach ( $gut[1] as $json_str ) {
        $data = json_decode( $json_str, true );
        if ( empty( $data['id'] ) ) continue;
        $id = (int) $data['id'];
        if ( isset( $seen[ $id ] ) ) continue;
        $seen[ $id ] = true;
        $items[] = [ 'id' => $id, 'src' => wp_get_attachment_url( $id ) ?: '' ];
    }

    /* ── 2. Balises <audio> et <source> ── */
    if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
        // Passe 1 : balises <audio src="…">
        $p = new WP_HTML_Tag_Processor( $content );
        while ( $p->next_tag( 'audio' ) ) {
            $src = (string) ( $p->get_attribute( 'src' ) ?? '' );
            $did = $p->get_attribute( 'data-id' );
            $id  = $did ? (int) $did : ( $src ? ( attachment_url_to_postid( $src ) ?: null ) : null );
            if ( $id && isset( $seen[ $id ] ) ) continue;
            if ( $src || $id ) {
                if ( $id ) $seen[ $id ] = true;
                $items[] = [ 'id' => $id, 'src' => $src ];
            }
        }

        // Passe 2 : balises <source> (enfants de <audio>)
        // Limitation : sans tag_closers on ne peut pas distinguer <audio><source>
        // de <video><source>. On filtre donc strictement par type MIME audio/
        // ou extension audio connue (#7). Un <source src="x.m4a" type="audio/mp4">
        // dans un <video> serait capté — edge case très marginal en pratique.
        $p2 = new WP_HTML_Tag_Processor( $content );
        while ( $p2->next_tag( 'source' ) ) {
            $src  = (string) ( $p2->get_attribute( 'src' )  ?? '' );
            $type = (string) ( $p2->get_attribute( 'type' ) ?? '' );
            if ( ! $src ) continue;
            if ( ! alta_is_audio_source( $src, $type ) ) continue;
            $id = attachment_url_to_postid( $src ) ?: null;
            if ( $id && isset( $seen[ $id ] ) ) continue;
            if ( $id ) $seen[ $id ] = true;
            $items[] = [ 'id' => $id, 'src' => $src ];
        }
    } else {
        // Fallback regex
        preg_match_all(
            '/\bsrc=["\']([^"\']+\.(?:mp3|ogg|wav|flac|aac|m4a|opus|wma))["\']/i',
            $content,
            $am
        );
        foreach ( $am[1] as $src ) {
            $id = attachment_url_to_postid( $src ) ?: null;
            if ( $id && isset( $seen[ $id ] ) ) continue;
            if ( $id ) $seen[ $id ] = true;
            $items[] = [ 'id' => $id, 'src' => $src ];
        }
    }

    /* ── 3. Shortcode [audio src="…"] ── */
    if ( has_shortcode( $content, 'audio' ) ) {
        preg_match_all( '/\[audio\b[^\]]*\bsrc=["\']([^"\']+)["\']/i', $content, $sc );
        foreach ( $sc[1] as $src ) {
            $id = attachment_url_to_postid( $src ) ?: null;
            if ( $id && isset( $seen[ $id ] ) ) continue;
            if ( $id ) $seen[ $id ] = true;
            $items[] = [ 'id' => $id, 'src' => $src ];
        }
    }

    return $items;
}

/**
 * True si src/type correspond à une source audio.
 * Filtre strict : type MIME audio/* ou extension audio sans type MIME déclaré.
 * Les types vidéo (video/*) sont explicitement exclus (#7).
 */
function alta_is_audio_source( string $src, string $type ): bool {
    if ( str_starts_with( $type, 'audio/' ) ) return true;
    if ( str_starts_with( $type, 'video/' ) ) return false;
    // Pas de type MIME : se fier à l'extension
    return (bool) preg_match(
        '/\.(mp3|ogg|wav|flac|aac|m4a|opus|wma)(\?.*)?$/i',
        $src
    );
}

// ─── Handler AJAX ─────────────────────────────────────────────────────────────

function alta_ajax_handler(): void {
    check_ajax_referer( 'alta_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission refusée.' );

    // #3 : pas de @ pour masquer les erreurs
    if ( function_exists( 'set_time_limit' ) ) set_time_limit( 300 );

    $batch_size = 50;
    $offset     = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

    // #2 : sanitize sur les valeurs string
    $diag_only     = ( sanitize_text_field( $_POST['diag_only']     ?? '0' ) === '1' );
    $skip_existing = ( sanitize_text_field( $_POST['skip_existing'] ?? '1' ) === '1' );

    $total = (int) wp_count_posts( 'post' )->publish;

    $posts = get_posts( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $batch_size,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ] );

    $log             = [];
    $audios_found    = 0;
    $audios_written  = 0;   // #15 : compteurs séparés
    $audios_skipped  = 0;
    $audios_no_id    = 0;   // #2 : détectés mais hors médiathèque
    $posts_processed = 0;

    foreach ( $posts as $post ) {
        $posts_processed++;
        $title   = html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES, 'UTF-8' );
        $content = $post->post_content;

        $audios        = alta_find_audios( $content );
        $counter_rec   = 1;
        $counter_audio = 1;
        $entry_audios  = [];

        foreach ( $audios as $a ) {
            $id      = $a['id'];
            $src_url = $a['src'];

            if ( ! $id && ! $src_url ) continue;
            if ( ! $src_url && $id ) $src_url = wp_get_attachment_url( $id ) ?: '';

            $filename = basename( urldecode( $src_url ) );
            $is_rec   = alta_is_recording( $src_url );
            $label    = $is_rec
                ? 'enregistrement audio ' . $counter_rec++
                : 'audio '                . $counter_audio++;

            $alt_preview = $title . ' / ' . $label;

            // #2 : statut 'no_id' si l'audio est détecté mais non résolvable en attachment
            if ( $id ) {
                $status = alta_update( $id, $alt_preview, $diag_only, $skip_existing );
            } else {
                $status = 'no_id'; // URL externe ou non trouvée dans la médiathèque
            }

            // #1 : 'found' sans $id = non traitable → ne compte pas comme "serait écrit"
            if ( $status === 'written' )                         $audios_written++;
            elseif ( $status === 'skipped' )                     $audios_skipped++;
            elseif ( $status === 'no_id' )                       $audios_no_id++;
            elseif ( $status === 'found' && $diag_only && $id )  $audios_written++;

            $entry_audios[] = [
                'alt_preview' => $alt_preview,
                'filename'    => $filename ?: ( $id ? "ID:{$id}" : '?' ),
                'written'     => $status === 'written',
                'skipped'     => $status === 'skipped',
                'no_id'       => $status === 'no_id',  // #2 : audio hors médiathèque
            ];
        }

        $log[] = [ 'title' => $title, 'audios' => $entry_audios ];
    }

    wp_send_json_success( [
        'offset'          => $offset + $posts_processed,
        'total'           => $total,
        'done'            => empty( $posts ) || ( $offset + $posts_processed ) >= $total,
        'audios_written'  => $audios_written,
        'audios_skipped'  => $audios_skipped,
        'audios_no_id'    => $audios_no_id,
        'posts_processed' => $posts_processed,
        'log'             => $log,
        'new_nonce'       => wp_create_nonce( 'alta_nonce' ),
    ] );
}

add_action( 'wp_ajax_alta_process', 'alta_ajax_handler' );
