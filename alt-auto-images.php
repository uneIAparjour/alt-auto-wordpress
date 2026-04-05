<?php
/**
 * Plugin Name:  Alt Auto — uneIAparjour
 * Description:  Génère les textes alt des images et vidéos : capture / image / capture vidéo / vidéo.
 * Version:      1.9
 * Author:       uneIAparjour
 * License:      GPL-2.0
 *
 * Changelog v1.9 :
 *  - Mode diagnostic : prévisualise les alt sans écrire (bouton dédié + log détaillé).
 *  - Bouton « Copier le log » dans les deux modes.
 *  - Log enrichi : alt prévu + nom de fichier par média.
 *
 * Changelog v1.9 :
 *  - Mode diagnostic : affiche les alts qui seraient écrits sans rien modifier.
 *  - Log détaillé par image/vidéo (alt preview + nom de fichier) dans les deux modes.
 *  - Bouton « Copier le log » (texte brut).
 *  - Le verrou de concurrence est ignoré en mode diagnostic.
 *
 * Changelog v1.8 :
 *  - tag_closers : triple chemin WP 6.4+ / WP 6.2-6.3 / regex selon version.
 *  - $video_depth : compteur de profondeur remplace le booléen $in_video (vidéos imbriquées).
 *  - $total = 0 falsy : false !== get_transient() à la place de !$total.
 *  - mapWarningShown réinitialisé à chaque clic « Lancer ».
 *  - Source doublon : $resolved_video_ids évite les items redondants dans $items.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ALT_AUTO_VERSION',   '1.9' );
define( 'ALT_AUTO_TRANSIENT', 'alt_auto_url_map' );
define( 'ALT_AUTO_LOCK',      'alt_auto_lock' );
define( 'ALT_AUTO_LOCK_TTL',  5 * MINUTE_IN_SECONDS );

// ─── Menu ─────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_management_page(
        'Alt Auto — uneIAparjour',
        'Alt Auto Images',
        'manage_options',
        'alt-auto-uneiaparjour',
        'alt_auto_render_page'
    );
} );

// ─── Page d'administration ────────────────────────────────────────────────────

function alt_auto_render_page(): void {
    $total_posts       = (int) wp_count_posts( 'post' )->publish;
    $has_tag_processor = class_exists( 'WP_HTML_Tag_Processor' );
    $wp_64_plus        = version_compare( $GLOBALS['wp_version'], '6.4', '>=' );
    $locked            = ( false !== get_transient( ALT_AUTO_LOCK ) );

    $unlock_url = wp_nonce_url(
        add_query_arg( 'alt_auto_unlock', '1' ),
        'alt_auto_unlock_action'
    );

    if ( $has_tag_processor && $wp_64_plus ) {
        $engine_label = '<span style="color:green;">✅ WP_HTML_Tag_Processor — passe unique (WP 6.4+)</span>';
    } elseif ( $has_tag_processor ) {
        $engine_label = '<span style="color:green;">✅ WP_HTML_Tag_Processor — double passe (WP 6.2–6.3)</span>';
    } else {
        $engine_label = '<span style="color:orange;">⚠️ Regex — mettez WordPress à jour pour plus de fiabilité</span>';
    }
    ?>
    <div class="wrap">
        <h1>🖼️ Alt Auto Médias
            <span style="font-size:13px;font-weight:normal;color:#666;">
                — uneIAparjour v<?php echo esc_html( ALT_AUTO_VERSION ); ?>
            </span>
        </h1>

        <?php if ( $locked ) : ?>
        <div class="notice notice-warning" style="max-width:740px;">
            <p>
                ⚠️ Un traitement est en cours — ou a été interrompu (fermeture navigateur, timeout réseau…).
                <a href="<?php echo esc_url( $unlock_url ); ?>">Forcer le déverrouillage</a>
                sans risque si vous êtes sûr qu'aucun run n'est actif.
            </p>
        </div>
        <?php endif; ?>

        <div class="card" style="max-width:740px;padding:20px 24px;margin-top:16px;">
            <p style="margin-top:0;">
                Parcourt tous les articles publiés et renseigne <code>alt</code>
                pour chaque image et vidéo, <strong>y compris les images à la une</strong>.
                Si un même attachment est image à la une de plusieurs articles,
                c'est le <em>premier article traité</em> qui définit son alt.
            </p>
            <table style="border-collapse:collapse;font-size:13px;width:100%;margin-bottom:14px;">
                <tr style="background:#f6f7f7;">
                    <th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Nom de fichier contient « capture »</th>
                    <th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Type</th>
                    <th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Alt généré</th>
                </tr>
                <?php foreach ( [
                    [ '✅', 'image (à la une ou contenu)', 'Titre / capture 1' ],
                    [ '❌', 'image (à la une ou contenu)', 'Titre / image 1' ],
                    [ '✅', 'vidéo',                       'Titre / capture vidéo 1' ],
                    [ '❌', 'vidéo',                       'Titre / vidéo 1' ],
                ] as $i => $row ) : ?>
                <tr<?php echo $i % 2 === 0 ? '' : ' style="background:#f6f7f7;"'; ?>>
                    <td style="padding:6px 10px;border:1px solid #ddd;"><?php echo esc_html( $row[0] ); ?></td>
                    <td style="padding:6px 10px;border:1px solid #ddd;"><?php echo esc_html( $row[1] ); ?></td>
                    <td style="padding:6px 10px;border:1px solid #ddd;font-family:monospace;"><?php echo esc_html( $row[2] ); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <p style="color:#666;font-size:12px;margin:0 0 14px;">
                ℹ️ Pour les vidéos, <code>_wp_attachment_image_alt</code> est renseigné (lu par Yoast / RankMath).
            </p>
            <p>
                📦 <strong><?php echo esc_html( number_format( $total_posts ) ); ?> articles publiés</strong> —
                Moteur HTML : <?php echo $engine_label; ?>
            </p>
            <hr>
            <p>
                <label>
                    <input type="checkbox" id="alt-skip" checked>
                    Ne pas écraser les médias qui ont déjà un texte alt
                </label>
            </p>
            <p style="display:flex;gap:12px;flex-wrap:wrap;">
                <button id="alt-diag-btn" class="button button-secondary button-hero"
                    <?php echo $locked ? 'disabled' : ''; ?>>
                    🔍 Mode diagnostic (aucune écriture)
                </button>
                <button id="alt-start-btn" class="button button-primary button-hero"
                    <?php echo $locked ? 'disabled title="Déverrouillez d\'abord le run précédent"' : ''; ?>>
                    🚀 Écrire les alt
                </button>
            </p>
        </div>

        <div id="alt-progress" style="display:none;max-width:740px;margin-top:20px;">
            <div style="background:#e0e0e0;border-radius:6px;height:14px;overflow:hidden;margin-bottom:10px;">
                <div id="alt-bar"
                     style="background:#2271b1;height:14px;width:0%;transition:width .4s ease;border-radius:6px;">
                </div>
            </div>
            <p id="alt-status" style="color:#555;font-style:italic;">Initialisation…</p>
        </div>

        <div id="alt-log-wrap" style="display:none;max-width:740px;margin-top:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <strong id="alt-log-title" style="font-size:13px;"></strong>
                <button id="alt-copy-btn" class="button button-small">📋 Copier le log</button>
            </div>
            <div id="alt-log"
                 style="max-height:440px;overflow-y:auto;background:#fff;border:1px solid #ddd;
                        border-radius:4px;padding:12px 16px;font-family:monospace;font-size:12px;line-height:1.8;">
            </div>
        </div>

        <div id="alt-done" style="display:none;max-width:740px;margin-top:16px;">
            <div class="notice notice-success" style="padding:12px 16px;">
                <p id="alt-summary" style="margin:0;font-size:14px;"></p>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const diagBtn  = document.getElementById('alt-diag-btn');
        const runBtn   = document.getElementById('alt-start-btn');
        const prog     = document.getElementById('alt-progress');
        const bar      = document.getElementById('alt-bar');
        const status   = document.getElementById('alt-status');
        const logWrap  = document.getElementById('alt-log-wrap');
        const logTitle = document.getElementById('alt-log-title');
        const logEl    = document.getElementById('alt-log');
        const copyBtn  = document.getElementById('alt-copy-btn');
        const doneBox  = document.getElementById('alt-done');
        const summary  = document.getElementById('alt-summary');
        const skip     = document.getElementById('alt-skip');

        let nonce = <?php echo wp_json_encode( wp_create_nonce( 'alt_auto_nonce' ) ); ?>;

        let totalMedia      = 0;
        let totalPosts      = 0;
        let mapWarningShown = false;
        let diagMode        = false;

        /* ── Copie du log ── */
        copyBtn.addEventListener('click', () => {
            const lines = Array.from(logEl.querySelectorAll('div'))
                .map(d => d.textContent).join('\n');
            navigator.clipboard.writeText(lines).then(() => {
                copyBtn.textContent = '✅ Copié !';
                setTimeout(() => { copyBtn.textContent = '📋 Copier le log'; }, 2000);
            });
        });

        function reset(isDiag) {
            diagMode           = isDiag;
            logEl.innerHTML    = '';
            doneBox.style.display = 'none';
            prog.style.display = 'block';
            logWrap.style.display = 'block';
            bar.style.background = '#2271b1';
            bar.style.width    = '0%';
            totalMedia         = 0;
            totalPosts         = 0;
            mapWarningShown    = false;
        }

        function disableButtons() { if (diagBtn) diagBtn.disabled = true; if (runBtn) runBtn.disabled = true; }
        function enableButtons()  { if (diagBtn) diagBtn.disabled = false; if (runBtn) runBtn.disabled = false; }

        function onError(msg) {
            status.textContent = '❌ ' + msg;
            enableButtons();
        }

        if (diagBtn) diagBtn.addEventListener('click', () => {
            if (!confirm('Lancer le diagnostic sur tous les articles ?')) return;
            disableButtons();
            reset(true);
            logTitle.textContent = '🔍 Diagnostic — aucune écriture';
            run(0);
        });

        if (runBtn) runBtn.addEventListener('click', () => {
            if (!confirm('Écrire les alt sur toutes les images trouvées ?')) return;
            disableButtons();
            reset(false);
            logTitle.textContent = '🚀 Traitement en cours';
            run(0);
        });

        function run(offset) {
            fetch(ajaxurl, {
                method : 'POST',
                body   : new URLSearchParams({
                    action        : 'alt_auto_process',
                    nonce         : nonce,
                    offset        : offset,
                    skip_existing : skip.checked ? '1' : '0',
                    diag_only     : diagMode ? '1' : '0',
                }),
            })
            .then(r => r.json())
            .then(({ success, data }) => {
                if (!success) { onError(data ?? 'Erreur inconnue'); return; }

                if (data.new_nonce) nonce = data.new_nonce;

                totalMedia += data.media_processed;
                totalPosts += data.posts_processed;

                if (data.map_warning && !mapWarningShown) {
                    mapWarningShown = true;
                    const warn = document.createElement('div');
                    warn.style.color = 'orange';
                    warn.textContent = '⚠️ ' + data.map_warning;
                    logEl.appendChild(warn);
                }

                /* ── Renderer unifié (même structure en mode diag et écriture) ──
                   Chaque entry : { title, media: [{alt, filename, status}] }
                   status : 'found' (diag) | 'written' | 'skipped' (écriture)
                   Icônes : 🔍 trouvé · ✅ écrit · ⏭️ ignoré (déjà renseigné) */
                data.log.forEach(entry => {
                    const block = document.createElement('div');
                    block.style.cssText = 'border-bottom:1px solid #f0f0f0;padding-bottom:4px;margin-bottom:4px;';

                    // Titre de l'article
                    const titleRow = document.createElement('div');
                    const hico = document.createElement('span');
                    hico.textContent = entry.media.length > 0 ? '📄 ' : '⚪ ';
                    const strong = document.createElement('strong');
                    strong.textContent = entry.title;
                    titleRow.appendChild(hico);
                    titleRow.appendChild(strong);
                    block.appendChild(titleRow);

                    if (entry.media.length === 0) {
                        const none = document.createElement('div');
                        none.style.cssText = 'color:#999;padding-left:20px;';
                        none.textContent = '  (aucun média traité)';
                        block.appendChild(none);
                    }

                    entry.media.forEach(m => {
                        const ml = document.createElement('div');
                        ml.style.paddingLeft = '20px';
                        const ICONS = { found: '🔍 ', written: '✅ ', skipped: '⏭️ ' };
                        const mico = document.createElement('span');
                        mico.textContent = ICONS[m.status] ?? '   ';
                        const malt = document.createElement('span');
                        malt.style.fontFamily = 'monospace';
                        malt.textContent = m.alt;
                        const mfn = document.createElement('span');
                        mfn.style.cssText = 'color:#888;font-size:11px;';
                        mfn.textContent = '  ← ' + m.filename;
                        ml.appendChild(mico); ml.appendChild(malt); ml.appendChild(mfn);
                        block.appendChild(ml);
                    });

                    logEl.appendChild(block);
                });
                logEl.scrollTop = logEl.scrollHeight;

                const pct = data.total > 0
                    ? Math.min(100, Math.round((data.offset / data.total) * 100))
                    : 100;
                bar.style.width    = pct + '%';
                status.textContent =
                    `Article ${data.offset} / ${data.total} — ${totalMedia} média(s) traité(s)`;

                if (data.done) {
                    bar.style.background  = diagMode ? '#f0a500' : '#00a32a';
                    bar.style.width       = '100%';
                    status.textContent    = diagMode ? `🔍 Diagnostic terminé — ${totalMedia} image(s) dans ${totalPosts} article(s).` : '✅ Traitement terminé.';
                    doneBox.style.display = 'block';
                    summary.textContent   = diagMode
                        ? `Diagnostic : ${totalMedia} image(s) trouvée(s) sur ${totalPosts} articles. Cliquez « Écrire les alt » pour les traiter.`
                        : `✅ ${totalPosts} articles parcourus — ${totalMedia} média(s) mis à jour.`;
                    logTitle.textContent  = diagMode
                        ? `🔍 Diagnostic — ${totalMedia} image(s) trouvée(s)`
                        : `✅ Traitement — ${totalMedia} média(s) mis à jour`;
                    enableButtons();
                } else {
                    run(data.offset);
                }
            })
            .catch(err => onError('Erreur réseau : ' + err.message));
        }
    })();
    </script>
    <?php
}

// ─── Déverrouillage manuel ────────────────────────────────────────────────────

add_action( 'admin_init', function () {
    if ( ! isset( $_GET['alt_auto_unlock'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    check_admin_referer( 'alt_auto_unlock_action' );
    delete_transient( ALT_AUTO_LOCK );
    delete_transient( ALT_AUTO_TRANSIENT );
    wp_safe_redirect( remove_query_arg( [ 'alt_auto_unlock', '_wpnonce' ] ) );
    exit;
} );

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Parse les suffixes G/M/K de memory_limit.
 * Retourne -1 si illimité — la vérification `> 0` dans le handler l'ignore intentionnellement.
 */
function alt_auto_parse_bytes( string $val ): int {
    $val = trim( $val );
    $num = (int) $val;
    if ( $num < 0 ) return -1;
    return match ( strtolower( substr( $val, -1 ) ) ) {
        'g'     => $num * 1024 * 1024 * 1024,
        'm'     => $num * 1024 * 1024,
        'k'     => $num * 1024,
        default => $num,
    };
}

/**
 * Construit ou retourne (depuis transient) la map URL→attachment_ID.
 * _wp_attached_file ne contient jamais de suffixe de taille : pas de strip ici.
 * Le strip est fait une seule fois dans alt_auto_resolve_image_id sur le src HTML.
 *
 * @return array{ map: array<string,int>, warning: string }
 */
function alt_auto_get_url_map(): array {
    $cached = get_transient( ALT_AUTO_TRANSIENT );
    if ( is_array( $cached ) ) {
        return [ 'map' => $cached, 'warning' => '' ];
    }

    global $wpdb;
    $base_url = untrailingslashit( wp_upload_dir()['baseurl'] );

    $rows = $wpdb->get_results(
        "SELECT p.ID, pm.meta_value
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm
                 ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
         WHERE p.post_type = 'attachment'",
        ARRAY_A
    );

    $map = [];
    foreach ( $rows as $row ) {
        $map[ $base_url . '/' . $row['meta_value'] ] = (int) $row['ID'];
    }

    // Estimation sans serialize() (~120 octets/entrée : URL + ID + overhead)
    $warning  = '';
    $est_size = count( $map ) * 120;
    if ( $est_size > 500 * 1024 ) {
        $warning = sprintf(
            'La map URL→ID pèse environ %s Ko. '
            . 'Si vous utilisez Memcached, la mise en cache peut échouer (limite ~1 Mo). '
            . 'Le run continue normalement.',
            number_format( $est_size / 1024, 0 )
        );
    }

    set_transient( ALT_AUTO_TRANSIENT, $map, HOUR_IN_SECONDS );
    return [ 'map' => $map, 'warning' => $warning ];
}

/**
 * Résout l'attachment ID depuis un tableau $img et la map URL→ID.
 *
 * @param array{src: string, class: string, data_id: string} $img
 * @param array<string, int> $url_map
 */
function alt_auto_resolve_image_id( array $img, array $url_map ): ?int {
    if ( preg_match( '/\bwp-image-(\d+)\b/i', $img['class'], $m ) ) return (int) $m[1];
    if ( ! empty( $img['data_id'] ) )                                return (int) $img['data_id'];
    $src       = $img['src'];
    $src_clean = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $src );
    return $url_map[ $src ] ?? $url_map[ $src_clean ] ?? null;
}

/** True si le nom de fichier contient « capture » (insensible à la casse, URL-décodé). */
function alt_auto_is_capture( string $url ): bool {
    return stripos( urldecode( basename( $url ) ), 'capture' ) !== false;
}

/** Écrit le meta alt si les conditions sont réunies. */
function alt_auto_update( int $id, string $alt, bool $skip_existing ): bool {
    if ( $skip_existing
         && '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
        return false;
    }
    update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
    return true;
}

/**
 * Extrait les données de toutes les balises <img>.
 * WP_HTML_Tag_Processor si dispo (WP 6.2+), regex en fallback.
 *
 * @return list<array{src: string, class: string, data_id: string}>
 */
function alt_auto_extract_images( string $content ): array {
    $images = [];
    if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
        $p = new WP_HTML_Tag_Processor( $content );
        while ( $p->next_tag( 'img' ) ) {
            $images[] = [
                'src'     => (string) ( $p->get_attribute( 'src' )     ?? '' ),
                'class'   => (string) ( $p->get_attribute( 'class' )   ?? '' ),
                'data_id' => (string) ( $p->get_attribute( 'data-id' ) ?? '' ),
            ];
        }
    } else {
        preg_match_all( '/<img[^>]+>/i', $content, $m );
        foreach ( $m[0] as $tag ) {
            $src = $cls = $did = '';
            if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i',     $tag, $ms ) ) $src = $ms[1];
            if ( preg_match( '/\bclass=["\']([^"\']+)["\']/i',   $tag, $mc ) ) $cls = $mc[1];
            if ( preg_match( '/\bdata-id=["\']([^"\']+)["\']/i', $tag, $md ) ) $did = $md[1];
            $images[] = [ 'src' => $src, 'class' => $cls, 'data_id' => $did ];
        }
    }
    return $images;
}

/**
 * Extrait les données de toutes les vidéos d'un contenu.
 *
 * TRIPLE CHEMIN selon la version de WordPress :
 *
 * ── WP 6.4+ : passe unique avec tag_closers ────────────────────────────────
 *   next_tag(['tag_closers'=>'visit']) visite ouvrantes ET fermantes.
 *   $video_depth (compteur entier) remplace un booléen pour couvrir les
 *   <video> imbriquées (HTML invalide mais théoriquement possible).
 *   <SOURCE> capturée uniquement si $video_depth > 0 → ignore audio/<picture>.
 *   $resolved_video_ids évite les items en double quand un <video> a N <source>.
 *
 * ── WP 6.2–6.3 : deux passes sans tag_closers ─────────────────────────────
 *   is_tag_closer() a un comportement instable sur les balises autofermantes
 *   (<video />) en WP < 6.4. On sépare les passes <video> et <source> comme
 *   en v1.6, mais <source> reste filtré par position relative.
 *   Note : sans tracking de fermeture, on ne peut pas filtrer <source> hors
 *   <video> — risque théorique sur les contenus avec <audio><source>.
 *
 * ── Fallback regex ──────────────────────────────────────────────────────────
 *   Blocs <video>…</video> + <video src="…"> sans fermeture.
 *   Un <video src="x.mp4"></video> peut matcher les deux regex ;
 *   $seen[$src] déduplique.
 *
 * Dans les trois chemins : blocs Gutenberg <!-- wp:video {"id":XXX} --> traités en fin.
 *
 * @return list<array{id: int|null, src: string}>
 */
function alt_auto_extract_videos( string $content ): array {
    $items = [];
    $seen  = [];   // déduplication par src
    $wp64  = version_compare( $GLOBALS['wp_version'], '6.4', '>=' );

    // ── WP 6.4+ : passe unique ───────────────────────────────────────────────
    if ( class_exists( 'WP_HTML_Tag_Processor' ) && $wp64 ) {

        $p           = new WP_HTML_Tag_Processor( $content );
        $video_depth = 0;           // compteur de profondeur (robuste aux imbrications)
        $current_video_id     = null;
        $resolved_video_ids   = []; // IDs dont la source a déjà été résolue

        while ( $p->next_tag( [ 'tag_closers' => 'visit' ] ) ) {
            $tag       = $p->get_tag();
            $is_closer = $p->is_tag_closer();

            if ( 'VIDEO' === $tag ) {
                if ( $is_closer ) {
                    $video_depth--;
                    if ( $video_depth <= 0 ) {
                        $video_depth      = 0;
                        $current_video_id = null;
                    }
                } else {
                    $src = (string) ( $p->get_attribute( 'src' )     ?? '' );
                    $did = $p->get_attribute( 'data-id' );
                    $id  = $did !== null ? (int) $did : null;

                    $video_depth++;
                    $current_video_id = $id;

                    if ( $src && ! isset( $seen[ $src ] ) ) {
                        $items[]      = [ 'id' => $id, 'src' => $src ];
                        $seen[ $src ] = true;
                        if ( $id !== null ) $resolved_video_ids[ $id ] = true;
                    } elseif ( ! $src && $id ) {
                        // Pas de src sur la balise : placeholder en attente de <source>
                        $items[] = [ 'id' => $id, 'src' => '' ];
                    }
                }

            } elseif ( 'SOURCE' === $tag && $video_depth > 0 && ! $is_closer ) {
                // Hors <video> ($video_depth = 0) → ignoré (audio, picture, etc.)
                $src = (string) ( $p->get_attribute( 'src' ) ?? '' );

                // Ignorer les sources supplémentaires pour un ID déjà résolu
                // (ex. <source src="v.mp4"> + <source src="v.webm"> → un seul item)
                if ( $current_video_id !== null
                     && isset( $resolved_video_ids[ $current_video_id ] ) ) {
                    continue;
                }

                if ( $src && ! isset( $seen[ $src ] ) ) {
                    $n = count( $items );
                    if ( $n > 0
                         && '' === $items[ $n - 1 ]['src']
                         && $items[ $n - 1 ]['id'] === $current_video_id ) {
                        $items[ $n - 1 ]['src'] = $src;   // compléter le placeholder
                    } else {
                        $items[] = [ 'id' => $current_video_id, 'src' => $src ];
                    }
                    $seen[ $src ] = true;
                    if ( $current_video_id !== null ) {
                        $resolved_video_ids[ $current_video_id ] = true;
                    }
                }
            }
        }

    // ── WP 6.2–6.3 : deux passes ─────────────────────────────────────────────
    } elseif ( class_exists( 'WP_HTML_Tag_Processor' ) ) {

        // Passe 1 : balises <video> ouvrantes
        $p = new WP_HTML_Tag_Processor( $content );
        while ( $p->next_tag( 'video' ) ) {
            $src = (string) ( $p->get_attribute( 'src' )     ?? '' );
            $did = $p->get_attribute( 'data-id' );
            $id  = $did !== null ? (int) $did : null;
            if ( $src && ! isset( $seen[ $src ] ) ) {
                $items[]      = [ 'id' => $id, 'src' => $src ];
                $seen[ $src ] = true;
            } elseif ( ! $src && $id ) {
                $items[] = [ 'id' => $id, 'src' => '' ];
            }
        }

        // Passe 2 : balises <source>
        // Note : sans tracking de fermeture </video>, on ne peut pas filtrer les
        // <source> hors contexte vidéo. Risque marginal sur contenus avec <audio>.
        $p2 = new WP_HTML_Tag_Processor( $content );
        while ( $p2->next_tag( 'source' ) ) {
            $src = (string) ( $p2->get_attribute( 'src' ) ?? '' );
            if ( $src && ! isset( $seen[ $src ] ) ) {
                $n = count( $items );
                if ( $n > 0 && '' === $items[ $n - 1 ]['src'] ) {
                    $items[ $n - 1 ]['src'] = $src;
                } else {
                    $items[]      = [ 'id' => null, 'src' => $src ];
                }
                $seen[ $src ] = true;
            }
        }

    // ── Fallback regex ────────────────────────────────────────────────────────
    } else {

        preg_match_all( '/<video[\s\S]*?<\/video>/i', $content, $vid_blocks );
        foreach ( $vid_blocks[0] as $block ) {
            $src = '';
            if      ( preg_match( '/<video[^>]+\bsrc=["\']([^"\']+)["\']/i',  $block, $m ) ) $src = $m[1];
            elseif  ( preg_match( '/<source[^>]+\bsrc=["\']([^"\']+)["\']/i', $block, $m ) ) $src = $m[1];
            $id = null;
            if ( preg_match( '/\bdata-id=["\'](\d+)["\']/i', $block, $m ) ) $id = (int) $m[1];
            if ( $src && ! isset( $seen[ $src ] ) ) {
                $items[]      = [ 'id' => $id, 'src' => $src ];
                $seen[ $src ] = true;
            } elseif ( $id ) {
                $items[] = [ 'id' => $id, 'src' => '' ];
            }
        }

        // <video src="…"> sans balise de fermeture (HTML malformé, cas rare).
        // Un <video src="x.mp4"></video> peut matcher les deux regex ; $seen déduplique.
        preg_match_all( '/<video[^>]+\bsrc=["\']([^"\']+)["\']/i', $content, $vs );
        foreach ( $vs[1] as $src ) {
            if ( ! isset( $seen[ $src ] ) ) {
                $items[]      = [ 'id' => null, 'src' => $src ];
                $seen[ $src ] = true;
            }
        }
    }

    // Blocs Gutenberg <!-- wp:video {"id":XXX} --> (commun aux trois chemins)
    preg_match_all( '/<!--\s*wp:video\s*(\{[^}]+\})/i', $content, $gv );
    foreach ( $gv[1] as $json_str ) {
        $data = json_decode( $json_str, true );
        if ( empty( $data['id'] ) ) continue;
        $gid     = (int) $data['id'];
        $already = false;
        foreach ( $items as $vi ) {
            if ( (int) $vi['id'] === $gid ) { $already = true; break; }
        }
        if ( ! $already ) $items[] = [ 'id' => $gid, 'src' => '' ];
    }

    return $items;
}

// ─── Handler AJAX ─────────────────────────────────────────────────────────────

function alt_auto_ajax_handler(): void {

    check_ajax_referer( 'alt_auto_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission refusée.' );

    @set_time_limit( 300 );

    $batch_size    = 50;
    $offset        = max( 0, (int) ( $_POST['offset']        ?? 0 ) );
    $diag_only     = ( ( $_POST['diag_only']     ?? '0' ) === '1' );
    $skip_existing = ( ( $_POST['skip_existing'] ?? '1' ) === '1' );
    $diag_only     = ( ( $_POST['diag_only']     ?? '0' ) === '1' );

    // ── Verrou de concurrence (mode écriture uniquement) ────────────────────────
    if ( ! $diag_only ) {
        if ( $offset === 0 ) {
            if ( false !== get_transient( ALT_AUTO_LOCK ) ) {
                wp_send_json_error(
                    'Un traitement est déjà en cours. '
                    . 'Attendez sa fin ou déverrouillez depuis la page du plugin.'
                );
            }
            $total = (int) wp_count_posts( 'post' )->publish;
            set_transient( ALT_AUTO_LOCK, $total, ALT_AUTO_LOCK_TTL );
            delete_transient( ALT_AUTO_TRANSIENT );
        } else {
            $lock_val = get_transient( ALT_AUTO_LOCK );
            $total    = ( false !== $lock_val )
                ? (int) $lock_val
                : (int) wp_count_posts( 'post' )->publish;
            set_transient( ALT_AUTO_LOCK, $total, ALT_AUTO_LOCK_TTL );
        }
    } else {
        // Mode diagnostic : aucun verrou, total recalculé à chaque batch (pas d'écriture)
        $total = (int) wp_count_posts( 'post' )->publish;
    }

    // ── Map URL→ID ────────────────────────────────────────────────────────────
    $url_map_result = alt_auto_get_url_map();
    $url_map        = $url_map_result['map'];
    $map_warning    = $url_map_result['warning'];

    // ── Mémoire (-1 = illimité, ignoré via > 0 intentionnellement) ───────────
    $mem_limit = alt_auto_parse_bytes( (string) ini_get( 'memory_limit' ) );

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
    $media_processed = 0;
    $processed_count = 0;

    foreach ( $posts as $post ) {

        if ( $mem_limit > 0 && memory_get_usage( true ) > $mem_limit * 0.80 ) break;
        $processed_count++;

        $title   = html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES, 'UTF-8' );
        $content = $post->post_content;
        $hits    = 0;

        $c = [ 'capture' => 1, 'image' => 1, 'capture_video' => 1, 'video' => 1 ];
        $seen_image_ids = [];

        // ── IMAGE À LA UNE ────────────────────────────────────────────────────
        $entry_media = [];   // log détaillé : [ ['alt'=>…,'filename'=>…,'status'=>…] ]

        $featured_id = (int) get_post_thumbnail_id( $post->ID );
        if ( $featured_id ) {
            $feat_url = wp_get_attachment_url( $featured_id ) ?: '';
            $is_cap   = alt_auto_is_capture( $feat_url );
            $label    = $is_cap ? 'capture ' . $c['capture']++ : 'image ' . $c['image']++;
            $alt      = $title . ' / ' . $label;
            if ( $diag_only ) {
                $status = 'found';
                $hits++;
                $media_processed++;
            } else {
                $status = alt_auto_update( $featured_id, $alt, $skip_existing ) ? 'written' : 'skipped';
                if ( 'written' === $status ) { $hits++; $media_processed++; }
            }
            $entry_media[] = [ 'alt' => $alt, 'filename' => basename( $feat_url ), 'status' => $status ];
            $seen_image_ids[ $featured_id ] = true;
        }

        // ── IMAGES DE CONTENU ─────────────────────────────────────────────────
        foreach ( alt_auto_extract_images( $content ) as $img ) {
            $id = alt_auto_resolve_image_id( $img, $url_map );
            if ( ! $id || isset( $seen_image_ids[ $id ] ) ) continue;
            $seen_image_ids[ $id ] = true;

            $src_url = $img['src'] ?: ( wp_get_attachment_url( $id ) ?: '' );
            $is_cap  = alt_auto_is_capture( $src_url );
            $label   = $is_cap ? 'capture ' . $c['capture']++ : 'image ' . $c['image']++;
            $alt     = $title . ' / ' . $label;
            if ( $diag_only ) {
                $status = 'found';
                $hits++; $media_processed++;
            } else {
                $status = alt_auto_update( $id, $alt, $skip_existing ) ? 'written' : 'skipped';
                if ( 'written' === $status ) { $hits++; $media_processed++; }
            }
            $entry_media[] = [ 'alt' => $alt, 'filename' => basename( $src_url ), 'status' => $status ];
        }

        // ── VIDÉOS ───────────────────────────────────────────────────────────
        $seen_video_ids = [];

        foreach ( alt_auto_extract_videos( $content ) as $vi ) {
            $id      = $vi['id'];
            $src_url = $vi['src'];

            if ( ! $id && $src_url ) {
                $src_clean = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $src_url );
                $id = $url_map[ $src_url ] ?? $url_map[ $src_clean ] ?? null;
            }

            if ( ! $id || isset( $seen_video_ids[ $id ] ) ) continue;
            $seen_video_ids[ $id ] = true;

            if ( ! $src_url ) $src_url = wp_get_attachment_url( $id ) ?: '';

            $is_cap = alt_auto_is_capture( $src_url );
            $label  = $is_cap
                ? 'capture vidéo ' . $c['capture_video']++
                : 'vidéo '         . $c['video']++;
            $alt    = $title . ' / ' . $label;
            if ( $diag_only ) {
                $status = 'found';
                $hits++; $media_processed++;
            } else {
                $status = alt_auto_update( $id, $alt, $skip_existing ) ? 'written' : 'skipped';
                if ( 'written' === $status ) { $hits++; $media_processed++; }
            }
            $entry_media[] = [ 'alt' => $alt, 'filename' => basename( $src_url ), 'status' => $status ];
        }

        $log[] = [ 'title' => $title, 'media' => $entry_media ];
    }

    $new_offset = $offset + $processed_count;
    $done       = empty( $posts ) || $new_offset >= $total;

    if ( $done && ! $diag_only ) {
        delete_transient( ALT_AUTO_LOCK );
        delete_transient( ALT_AUTO_TRANSIENT );
    }

    wp_send_json_success( [
        'offset'          => $new_offset,
        'total'           => $total,
        'done'            => $done,
        'media_processed' => $media_processed,
        'posts_processed' => $processed_count,
        'log'             => $log,
        'map_warning'     => $map_warning,
        'new_nonce'       => wp_create_nonce( 'alt_auto_nonce' ),
    ] );
}

add_action( 'wp_ajax_alt_auto_process', 'alt_auto_ajax_handler' );
