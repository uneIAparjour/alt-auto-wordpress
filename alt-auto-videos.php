<?php
/**
 * Plugin Name:  Alt Auto Vidéos — uneIAparjour
 * Description:  Génère les textes alt des vidéos hébergées dans la médiathèque.
 *               Mode diagnostic : inspecte les articles sans rien écrire.
 *               Mode traitement : écrit les alt après validation.
 * Version:      1.1
 * Author:       uneIAparjour
 * License:      GPL-2.0
 *
 * Changelog v1.1 :
 *  - altv_is_capture() détecte aussi « enregistrement » (Mac screen recordings .mov).
 *  - Filtre <source> : video/quicktime et absence de type MIME acceptés.
 *  - Extension .m4v ajoutée.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ALTV_VERSION', '1.1' );

// ─── Menu ─────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_management_page(
        'Alt Auto Vidéos — uneIAparjour',
        'Alt Auto Vidéos',
        'manage_options',
        'alt-auto-videos',
        'altv_render_page'
    );
} );

// ─── Page ─────────────────────────────────────────────────────────────────────

function altv_render_page(): void {
    $total = (int) wp_count_posts( 'post' )->publish;
    ?>
    <div class="wrap">
        <h1>🎬 Alt Auto Vidéos
            <span style="font-size:13px;font-weight:normal;color:#666;">
                — uneIAparjour v<?php echo esc_html( ALTV_VERSION ); ?>
            </span>
        </h1>

        <div class="card" style="max-width:760px;padding:20px 24px;margin-top:16px;">
            <p style="margin-top:0;">
                Ce plugin traite uniquement les vidéos hébergées dans la médiathèque WordPress.
                Format des alt générés :
            </p>
            <table style="border-collapse:collapse;font-size:13px;margin-bottom:14px;">
                <tr style="background:#f6f7f7;">
                    <th style="padding:5px 10px;text-align:left;border:1px solid #ddd;">Nom de fichier contient « capture »</th>
                    <th style="padding:5px 10px;text-align:left;border:1px solid #ddd;">Alt généré</th>
                </tr>
                <tr>
                    <td style="padding:5px 10px;border:1px solid #ddd;">✅ oui</td>
                    <td style="padding:5px 10px;border:1px solid #ddd;font-family:monospace;">Titre de l'article / capture vidéo 1</td>
                </tr>
                <tr style="background:#f6f7f7;">
                    <td style="padding:5px 10px;border:1px solid #ddd;">❌ non</td>
                    <td style="padding:5px 10px;border:1px solid #ddd;font-family:monospace;">Titre de l'article / vidéo 1</td>
                </tr>
            </table>
            <p>📦 <strong><?php echo esc_html( number_format( $total ) ); ?> articles publiés</strong></p>
            <hr>
            <p>
                <label>
                    <input type="checkbox" id="altv-skip" checked>
                    Ne pas écraser les vidéos qui ont déjà un texte alt
                </label>
            </p>
            <p style="display:flex;gap:12px;flex-wrap:wrap;">
                <button id="altv-diag-btn" class="button button-secondary button-hero">
                    🔍 Mode diagnostic (aucune écriture)
                </button>
                <button id="altv-run-btn" class="button button-primary button-hero">
                    🚀 Écrire les alt
                </button>
            </p>
        </div>

        <!-- Barre de progression -->
        <div id="altv-progress" style="display:none;max-width:760px;margin-top:20px;">
            <div style="background:#e0e0e0;border-radius:6px;height:14px;overflow:hidden;margin-bottom:10px;">
                <div id="altv-bar"
                     style="background:#2271b1;height:14px;width:0%;transition:width .4s ease;border-radius:6px;">
                </div>
            </div>
            <p id="altv-status" style="color:#555;font-style:italic;">Initialisation…</p>
        </div>

        <!-- Log -->
        <div id="altv-log-wrap" style="display:none;max-width:760px;margin-top:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <strong id="altv-log-title" style="font-size:13px;"></strong>
                <button id="altv-copy-btn" class="button button-small">📋 Copier le log</button>
            </div>
            <div id="altv-log"
                 style="max-height:440px;overflow-y:auto;background:#fff;border:1px solid #ddd;
                        border-radius:4px;padding:12px 16px;font-family:monospace;font-size:12px;line-height:1.8;">
            </div>
        </div>

        <!-- Résumé final -->
        <div id="altv-done" style="display:none;max-width:760px;margin-top:16px;">
            <div class="notice notice-success" style="padding:12px 16px;">
                <p id="altv-summary" style="margin:0;font-size:14px;"></p>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const diagBtn  = document.getElementById('altv-diag-btn');
        const runBtn   = document.getElementById('altv-run-btn');
        const prog     = document.getElementById('altv-progress');
        const bar      = document.getElementById('altv-bar');
        const status   = document.getElementById('altv-status');
        const logWrap  = document.getElementById('altv-log-wrap');
        const logTitle = document.getElementById('altv-log-title');
        const logEl    = document.getElementById('altv-log');
        const copyBtn  = document.getElementById('altv-copy-btn');
        const doneBox  = document.getElementById('altv-done');
        const summary  = document.getElementById('altv-summary');
        const skip     = document.getElementById('altv-skip');

        let nonce = <?php echo wp_json_encode( wp_create_nonce( 'altv_nonce' ) ); ?>;
        let totalVideos = 0, totalArticles = 0;

        /* ── Copie du log ── */
        copyBtn.addEventListener('click', () => {
            const lines = Array.from(logEl.querySelectorAll('div'))
                .map(d => d.textContent).join('\n');
            navigator.clipboard.writeText(lines).then(() => {
                copyBtn.textContent = '✅ Copié !';
                setTimeout(() => { copyBtn.textContent = '📋 Copier le log'; }, 2000);
            });
        });

        function reset() {
            logEl.innerHTML      = '';
            doneBox.style.display = 'none';
            prog.style.display   = 'block';
            logWrap.style.display = 'block';
            bar.style.background = '#2271b1';
            bar.style.width      = '0%';
            totalVideos          = 0;
            totalArticles        = 0;
        }

        function disableButtons() {
            diagBtn.disabled = true;
            runBtn.disabled  = true;
        }
        function enableButtons() {
            diagBtn.disabled = false;
            runBtn.disabled  = false;
        }

        function onError(msg) {
            status.textContent = '❌ ' + msg;
            enableButtons();
        }

        diagBtn.addEventListener('click', () => {
            if (!confirm('Lancer le diagnostic sur tous les articles ?')) return;
            disableButtons();
            reset();
            logTitle.textContent = '🔍 Diagnostic — aucune écriture';
            run(0, true);
        });

        runBtn.addEventListener('click', () => {
            if (!confirm('Écrire les alt sur toutes les vidéos trouvées ?')) return;
            disableButtons();
            reset();
            logTitle.textContent = '🚀 Traitement en cours';
            run(0, false);
        });

        function run(offset, diagOnly) {
            fetch(ajaxurl, {
                method : 'POST',
                body   : new URLSearchParams({
                    action        : 'altv_process',
                    nonce         : nonce,
                    offset        : offset,
                    diag_only     : diagOnly ? '1' : '0',
                    skip_existing : skip.checked ? '1' : '0',
                }),
            })
            .then(r => r.json())
            .then(({ success, data }) => {
                if (!success) { onError(data ?? 'Erreur inconnue'); return; }

                if (data.new_nonce) nonce = data.new_nonce;

                totalVideos   += data.videos_found;
                totalArticles += data.posts_processed;

                /* Log structuré — XSS-safe */
                data.log.forEach(entry => {
                    const line = document.createElement('div');
                    line.style.borderBottom = '1px solid #f0f0f0';
                    line.style.paddingBottom = '4px';
                    line.style.marginBottom  = '4px';

                    /* Titre de l'article */
                    const title = document.createElement('div');
                    const titleIco = document.createElement('span');
                    titleIco.textContent = entry.videos.length > 0 ? '📄 ' : '⚪ ';
                    const titleEm = document.createElement('strong');
                    titleEm.textContent = entry.title;
                    title.appendChild(titleIco);
                    title.appendChild(titleEm);
                    line.appendChild(title);

                    if (entry.videos.length === 0) {
                        const none = document.createElement('div');
                        none.style.color = '#999';
                        none.style.paddingLeft = '20px';
                        none.textContent = '  (aucune vidéo détectée)';
                        line.appendChild(none);
                    }

                    /* Détail de chaque vidéo */
                    entry.videos.forEach(v => {
                        const vline = document.createElement('div');
                        vline.style.paddingLeft = '20px';

                        const ico = document.createElement('span');
                        ico.textContent = v.written ? '  ✅ ' : (v.skipped ? '  ⏭️ ' : '  🔍 ');

                        const info = document.createElement('span');
                        info.style.fontFamily = 'monospace';
                        info.textContent = v.alt_preview;

                        const meta = document.createElement('span');
                        meta.style.color = '#888';
                        meta.style.fontSize = '11px';
                        meta.textContent = '  ← ' + v.filename;

                        vline.appendChild(ico);
                        vline.appendChild(info);
                        vline.appendChild(meta);
                        line.appendChild(vline);
                    });

                    logEl.appendChild(line);
                });
                logEl.scrollTop = logEl.scrollHeight;

                const pct = data.total > 0
                    ? Math.min(100, Math.round((data.offset / data.total) * 100))
                    : 100;
                bar.style.width    = pct + '%';
                status.textContent = `Article ${data.offset} / ${data.total} `
                    + `— ${totalVideos} vidéo(s) détectée(s)`;

                if (data.done) {
                    bar.style.background  = diagOnly ? '#f0a500' : '#00a32a';
                    bar.style.width       = '100%';
                    status.textContent    = diagOnly
                        ? `🔍 Diagnostic terminé — ${totalVideos} vidéo(s) dans ${totalArticles} article(s).`
                        : `✅ Traitement terminé.`;
                    doneBox.style.display = 'block';
                    summary.textContent   = diagOnly
                        ? `Diagnostic : ${totalVideos} vidéo(s) trouvée(s) sur ${totalArticles} articles. `
                          + `Cliquez « Écrire les alt » pour les traiter.`
                        : `✅ ${totalArticles} articles parcourus — ${totalVideos} vidéo(s) traitée(s).`;
                    enableButtons();
                    logTitle.textContent = diagOnly
                        ? `🔍 Diagnostic — ${totalVideos} vidéo(s) trouvée(s)`
                        : `✅ Traitement — ${totalVideos} vidéo(s) mises à jour`;
                } else {
                    run(data.offset, diagOnly);
                }
            })
            .catch(err => onError('Erreur réseau : ' + err.message));
        }
    })();
    </script>
    <?php
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * True si le fichier est une capture vidéo (enregistrement d'écran).
 * Détecte deux patterns courants :
 *  - « capture »   : convention générique et Mac (Capture-decran-…)
 *  - « enregistrement » : Mac screen recording (Enregistrement-de-lecran-…)
 */
function altv_is_capture( string $url ): bool {
    $name = urldecode( basename( $url ) );
    return stripos( $name, 'capture' ) !== false
        || stripos( $name, 'enregistrement' ) !== false;
}

/** Écrit le meta alt si les conditions sont réunies. Retourne 'written', 'skipped' ou 'exists'. */
function altv_update( int $id, string $alt, bool $dry_run, bool $skip_existing ): string {
    if ( $dry_run ) return 'found';
    if ( $skip_existing
         && '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
        return 'skipped';
    }
    update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
    return 'written';
}

/**
 * Cherche toutes les vidéos dans un contenu d'article.
 * Stratégie en 4 couches pour maximiser la couverture :
 *
 *  1. Commentaires Gutenberg <!-- wp:video {"id":NNN} --> → ID direct, le plus fiable.
 *  2. WP_HTML_Tag_Processor sur <video src="…"> → extrait src + data-id.
 *  3. Regex sur <video src="…"> (fallback si WP < 6.2).
 *  4. Shortcode WordPress [video src="…"].
 *
 * Retourne une liste de [ 'id' => int|null, 'src' => string ], dédupliquée par ID.
 *
 * @return list<array{id: int|null, src: string}>
 */
function altv_find_videos( string $content ): array {
    $items  = [];
    $seen   = []; // IDs déjà trouvés

    /* ── 1. Blocs Gutenberg (méthode la plus fiable pour les glisser-déposer) ── */
    preg_match_all( '/<!--\s*wp:video\s*(\{[^}]*\})\s*-->/i', $content, $gut );
    foreach ( $gut[1] as $json_str ) {
        $data = json_decode( $json_str, true );
        if ( empty( $data['id'] ) ) continue;
        $id = (int) $data['id'];
        if ( isset( $seen[ $id ] ) ) continue;
        $seen[ $id ] = true;
        // Récupérer le src depuis l'attachment
        $src = wp_get_attachment_url( $id ) ?: '';
        $items[] = [ 'id' => $id, 'src' => $src ];
    }

    /* ── 2. Balises <video src="…"> via WP_HTML_Tag_Processor ── */
    if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
        $p = new WP_HTML_Tag_Processor( $content );
        while ( $p->next_tag( 'video' ) ) {
            $src = (string) ( $p->get_attribute( 'src' ) ?? '' );
            $did = $p->get_attribute( 'data-id' );
            $id  = $did ? (int) $did : ( $src ? attachment_url_to_postid( $src ) : null );
            if ( $id && isset( $seen[ $id ] ) ) continue;
            if ( $src || $id ) {
                if ( $id ) $seen[ $id ] = true;
                $items[] = [ 'id' => $id ?: null, 'src' => $src ];
            }
        }
        /* Balises <source> dans les blocs <video> */
        $p2 = new WP_HTML_Tag_Processor( $content );
        while ( $p2->next_tag( 'source' ) ) {
            $src  = (string) ( $p2->get_attribute( 'src' ) ?? '' );
            $type = (string) ( $p2->get_attribute( 'type' ) ?? '' );
            if ( ! $src ) continue;
            // Ne traiter que les sources vidéo (type video/* ou extension vidéo)
            // video/quicktime = .mov (Mac screen recordings)
            $is_video = ( str_starts_with( $type, 'video/' )
                || '' === $type  // pas de type MIME déclaré : se fier à l'extension
                || preg_match( '/\.(mp4|webm|ogv|mov|avi|mkv|m4v)(\?.*)?$/i', $src ) );
            if ( ! $is_video ) continue;
            $id = attachment_url_to_postid( $src ) ?: null;
            if ( $id && isset( $seen[ $id ] ) ) continue;
            if ( $id ) $seen[ $id ] = true;
            $items[] = [ 'id' => $id, 'src' => $src ];
        }
    } else {
        /* ── 3. Fallback regex ── */
        preg_match_all( '/<video[^>]+\bsrc=["\']([^"\']+)["\']/i', $content, $vm );
        foreach ( $vm[1] as $src ) {
            $id = attachment_url_to_postid( $src ) ?: null;
            if ( $id && isset( $seen[ $id ] ) ) continue;
            if ( $id ) $seen[ $id ] = true;
            $items[] = [ 'id' => $id, 'src' => $src ];
        }
    }

    /* ── 4. Shortcode [video src="…"] ── */
    if ( has_shortcode( $content, 'video' ) ) {
        preg_match_all( '/\[video\b[^\]]*\bsrc=["\']([^"\']+)["\']/i', $content, $sc );
        foreach ( $sc[1] as $src ) {
            $id = attachment_url_to_postid( $src ) ?: null;
            if ( $id && isset( $seen[ $id ] ) ) continue;
            if ( $id ) $seen[ $id ] = true;
            $items[] = [ 'id' => $id, 'src' => $src ];
        }
    }

    return $items;
}

// ─── Handler AJAX ─────────────────────────────────────────────────────────────

function altv_ajax_handler(): void {
    check_ajax_referer( 'altv_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission refusée.' );

    @set_time_limit( 300 );

    $batch_size    = 50;
    $offset        = max( 0, (int) ( $_POST['offset']        ?? 0 ) );
    $diag_only     = ( ( $_POST['diag_only']     ?? '0' ) === '1' );
    $skip_existing = ( ( $_POST['skip_existing'] ?? '1' ) === '1' );
    $total         = (int) wp_count_posts( 'post' )->publish;

    $posts = get_posts( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => $batch_size,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ] );

    $log            = [];
    $videos_found   = 0;
    $posts_processed = 0;

    foreach ( $posts as $post ) {
        $posts_processed++;
        $title   = html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES, 'UTF-8' );
        $content = $post->post_content;

        $videos  = altv_find_videos( $content );
        $counter_cap = 1;
        $counter_vid = 1;
        $entry_videos = [];

        foreach ( $videos as $v ) {
            $id      = $v['id'];
            $src_url = $v['src'];

            if ( ! $id && ! $src_url ) continue;

            // Récupérer l'URL réelle si manquante
            if ( ! $src_url && $id ) {
                $src_url = wp_get_attachment_url( $id ) ?: '';
            }

            $filename = basename( urldecode( $src_url ) );
            $is_cap   = altv_is_capture( $src_url );
            $label    = $is_cap
                ? 'capture vidéo ' . $counter_cap++
                : 'vidéo '         . $counter_vid++;

            $alt_preview = $title . ' / ' . $label;

            $status = 'found'; // mode diagnostic
            if ( $id && ! $diag_only ) {
                $status = altv_update( $id, $alt_preview, false, $skip_existing );
            }

            $entry_videos[] = [
                'alt_preview' => $alt_preview,
                'filename'    => $filename ?: ( $id ? "ID:{$id}" : '?' ),
                'written'     => $status === 'written',
                'skipped'     => $status === 'skipped',
                'id'          => $id,
            ];
            $videos_found++;
        }

        $log[] = [ 'title' => $title, 'videos' => $entry_videos ];
    }

    $new_offset = $offset + $posts_processed;

    wp_send_json_success( [
        'offset'          => $new_offset,
        'total'           => $total,
        'done'            => empty( $posts ) || $new_offset >= $total,
        'videos_found'    => $videos_found,
        'posts_processed' => $posts_processed,
        'log'             => $log,
        'new_nonce'       => wp_create_nonce( 'altv_nonce' ),
    ] );
}

add_action( 'wp_ajax_altv_process', 'altv_ajax_handler' );
