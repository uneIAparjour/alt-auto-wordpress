# Alt Auto — uneIAparjour

Quatre plugins WordPress pour générer automatiquement les textes `alt` des images, vidéos et audios d'un site, développés pour [uneIAparjour.fr](https://uneiaparjour.fr).

Créés en collaboration avec Claude (Anthropic), à partir d'un besoin concret : renseigner les alt de plus de 8 000 médias accumulés sur un site de veille quotidienne sur l'IA, puis automatiser le traitement pour les nouveaux contenus.

---

## Les quatre plugins

### 1. `alt-auto-images.php` — Traitement en masse des images

Parcourt tous les articles publiés et renseigne le champ `alt` de chaque image trouvée dans le contenu, y compris les images à la une.

**Fonctionnalités :**
- Mode **🔍 Diagnostic** : prévisualise les alt générés sans rien écrire en base
- Mode **🚀 Traitement** : écrit les alt après validation
- Traitement par batch de 50 articles (aucun timeout)
- Barre de progression en temps réel avec log détaillé
- Bouton « Copier le log » pour archiver le résultat
- Ne touche jamais un alt déjà renseigné (option activée par défaut)
- Verrou de concurrence (un seul run simultané)
- Compatible WP_HTML_Tag_Processor (WP 6.2+) avec fallback regex

**Accès :** Outils → Alt Auto Images

---

### 2. `alt-auto-videos.php` — Traitement en masse des vidéos

Même logique que le plugin images, dédié aux vidéos hébergées dans la médiathèque WordPress.

**Fonctionnalités :**
- Mode **🔍 Diagnostic** : affiche pour chaque vidéo le nom de fichier et l'alt prévu
- Mode **🚀 Traitement** : écrit les alt après validation
- Détection en 4 couches : blocs Gutenberg `<!-- wp:video -->`, balises `<video>`, balises `<source>`, shortcodes `[video]`
- Compatible `.mp4`, `.mov`, `.webm`, `.m4v` et autres formats vidéo courants

**Accès :** Outils → Alt Auto Vidéos

---

### 3. `alt-auto-audios.php` — Traitement en masse des audios

Même logique que le plugin vidéos, dédié aux fichiers audio hébergés dans la médiathèque WordPress.

**Fonctionnalités :**
- Mode **🔍 Diagnostic** : affiche pour chaque audio le nom de fichier et l'alt prévu, en distinguant les audios qui seraient ignorés (⏭️) de ceux qui seraient écrits (🔍) et ceux hors médiathèque (⚠️)
- Mode **🚀 Traitement** : écrit les alt après validation
- Détection en 3 couches : blocs Gutenberg `<!-- wp:audio -->`, balises `<audio>` et `<source>`, shortcodes `[audio]`
- Compatible `.mp3`, `.ogg`, `.wav`, `.flac`, `.aac`, `.m4a`, `.opus`, `.wma`

**Accès :** Outils → Alt Auto Audios

---

### 4. `alt-auto-on-save.php` — Automatisation sur publication

Se déclenche automatiquement à chaque publication ou mise à jour d'un article. Aucune interface, aucun bouton : les alt sont écrits en arrière-plan, sans intervention manuelle.

**Fonctionnalités :**
- Utilise `parse_blocks()` (API native WordPress) pour une extraction fiable des blocs Gutenberg
- Gère `core/image`, `core/video`, `core/audio`, `core/gallery`, `core/cover`, `core/media-text` et tous les blocs imbriqués
- Fallback sur le HTML brut pour les blocs tiers et le Classic Editor
- Détection de `<source>` dans `<video>` et `<audio>` avec filtrage type MIME
- Ne touche jamais un alt déjà renseigné

**Installation :** déposer dans `/wp-content/plugins/` et activer — fonctionne immédiatement, sans configuration.

---

## Logique de nommage des alt

Les quatre plugins appliquent la même convention :

| Type de fichier | Alt généré |
|---|---|
| Image dont le nom contient `capture` | `Titre de l'article / capture 1` |
| Image standard | `Titre de l'article / image 1` |
| Vidéo dont le nom contient `capture`, `enregistrement` ou `screenrecording` | `Titre de l'article / capture vidéo 1` |
| Vidéo standard | `Titre de l'article / vidéo 1` |
| Audio dont le nom contient `enregistrement`, `recording` ou `capture` | `Titre de l'article / enregistrement audio 1` |
| Audio standard | `Titre de l'article / audio 1` |

Les compteurs sont indépendants par type et remis à zéro à chaque article. L'image à la une est toujours traitée en premier (numéro 1 dans la séquence).

---

## Installation

### Traitement initial (une seule fois)

1. Déposer `alt-auto-images.php` dans `/wp-content/plugins/`
2. Activer → Outils → Alt Auto Images → lancer le diagnostic, puis le traitement
3. Déposer `alt-auto-videos.php` dans `/wp-content/plugins/`
4. Activer → Outils → Alt Auto Vidéos → lancer le diagnostic, puis le traitement
5. Déposer `alt-auto-audios.php` dans `/wp-content/plugins/`
6. Activer → Outils → Alt Auto Audios → lancer le diagnostic, puis le traitement
7. Désactiver et supprimer les trois plugins une fois le traitement terminé

### Automatisation (permanent)

8. Déposer `alt-auto-on-save.php` dans `/wp-content/plugins/`
9. Activer → les nouveaux articles sont traités automatiquement à la publication

---

## Compatibilité

- WordPress 5.9+ (recommandé : 6.4+ pour le moteur HTML le plus fiable)
- PHP 8.0+
- Aucune dépendance externe

---

## Contexte de création

Ces plugins ont été développés pour [uneIAparjour.fr](https://uneiaparjour.fr), un projet de veille quotidienne sur les outils d'IA générative lancé en février 2023 par Bertrand Formet. Le site comptait plus de 1 100 articles et 8 700 médias sans texte alternatif — un chantier d'accessibilité et de SEO impossible à traiter manuellement.

Le développement a suivi un processus itératif avec plusieurs cycles de revue de code, chaque version intégrant les corrections de la précédente. Les plugins sont passés par plusieurs révisions majeures avant d'atteindre leur forme actuelle.

---

## Licence

MIT — voir [LICENSE](LICENSE)
