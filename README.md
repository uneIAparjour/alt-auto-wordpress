# Alt Auto — uneIAparjour

Trois plugins WordPress pour générer automatiquement les textes `alt` des images et vidéos d'un site, développés pour [uneIAparjour.fr](https://uneiaparjour.fr).

Créés en collaboration entre **Bertrand Formet** et **Claude** (Anthropic), à partir d'un besoin concret : renseigner les alt de plus de 8 000 médias accumulés sur un site de veille quotidienne sur l'IA, puis automatiser le traitement pour les nouveaux contenus.

---

## Les trois plugins

### 1. `alt-auto-uneiaparjour.php` — Traitement en masse des images

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

### 3. `alt-auto-on-save.php` — Automatisation sur publication

Se déclenche automatiquement à chaque publication ou mise à jour d'un article. Aucune interface, aucun bouton : les alt sont écrits en arrière-plan, sans intervention manuelle.

**Fonctionnalités :**
- Utilise `parse_blocks()` (API native WordPress) pour une extraction fiable des blocs Gutenberg
- Gère `core/image`, `core/video`, `core/gallery`, `core/cover`, `core/media-text` et tous les blocs imbriqués
- Fallback sur le HTML brut pour les blocs tiers et le Classic Editor
- Détection de `<source>` dans `<video>` avec filtrage type MIME (ignore les sources audio)
- Ne touche jamais un alt déjà renseigné

**Installation :** déposer dans `/wp-content/plugins/` et activer — fonctionne immédiatement, sans configuration.

---

## Logique de nommage des alt

Les trois plugins appliquent la même convention :

| Type de fichier | Alt généré |
|---|---|
| Image dont le nom contient `capture` | `Titre de l'article / capture 1` |
| Image standard | `Titre de l'article / image 1` |
| Vidéo dont le nom contient `capture` ou `enregistrement` ou `screenrecording` | `Titre de l'article / capture vidéo 1` |
| Vidéo standard | `Titre de l'article / vidéo 1` |

Les compteurs sont indépendants par type et remis à zéro à chaque article. L'image à la une est toujours traitée en premier (numéro 1 dans la séquence).

---

## Installation

### Traitement initial (une seule fois)

1. Déposer `alt-auto-uneiaparjour.php` dans `/wp-content/plugins/`
2. Activer → Outils → Alt Auto Images → lancer le diagnostic, puis le traitement
3. Déposer `alt-auto-videos.php` dans `/wp-content/plugins/`
4. Activer → Outils → Alt Auto Vidéos → lancer le diagnostic, puis le traitement
5. Désactiver et supprimer les deux plugins une fois le traitement terminé

### Automatisation (permanent)

6. Déposer `alt-auto-on-save.php` dans `/wp-content/plugins/`
7. Activer → les nouveaux articles sont traités automatiquement à la publication

---

## Compatibilité

- WordPress 5.9+ (recommandé : 6.4+ pour le moteur HTML le plus fiable)
- PHP 8.0+
- Aucune dépendance externe

---

## Contexte de création

Ces plugins ont été développés pour [uneIAparjour.fr](https://uneiaparjour.fr), un projet de veille quotidienne sur les outils d'IA générative lancé en février 2023 par Bertrand Formet. Le site comptait plus de 1 100 articles et 8 700 médias sans texte alternatif — un chantier d'accessibilité et de SEO impossible à traiter manuellement.

Le développement a suivi un processus itératif avec plusieurs cycles de revue de code, chaque version intégrant les corrections de la précédente. Les plugins sont passés par huit révisions majeures avant d'atteindre leur forme actuelle.

---

## Licence

MIT — voir [LICENSE](LICENSE)
