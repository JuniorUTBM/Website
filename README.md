# Junior UTBM — site web

Site de la Junior UTBM, l'association étudiante de l'UTBM qui confie des études d'ingénierie aux étudiants.

- Production : https://junior.utbm.fr/
- Aperçu (GitHub Pages) : https://juniorutbm.github.io/Website/

Site statique écrit à la main : HTML, une feuille de style, un petit script. Pas de framework ni d'étape de build.

## Structure

| Chemin | Contenu |
|---|---|
| `index.html` | Accueil (entreprises) |
| `etudiants/`, `partenaires/`, `contact/` | Pages du site (`index.html` dans chaque dossier) |
| `404.html` | Page d'erreur |
| `assets/css/style.css` | Tous les styles (couleurs de la marque : orange `#FE9326`, bleu `#2D75BB`) |
| `assets/js/main.js` | Carrousel de l'équipe, texte animé, messages du formulaire |
| `assets/img/`, `assets/fonts/`, `assets/docs/` | Images, polices, plaquette et mentions légales (PDF) |
| `contact/envoyer.php` | Envoie le formulaire de contact par e-mail à contact@juniorutbm.fr |
| `sitemap.xml`, `robots.txt` | Référencement |

L'en-tête et le pied de page sont recopiés dans chaque page : une modification doit être faite dans les 5 fichiers HTML.

## Modifier le site

1. Modifier les fichiers, puis vérifier en local : `python3 -m http.server` à la racine, puis ouvrir http://localhost:8000.
2. Utiliser des chemins relatifs (`../assets/...`) : le site doit fonctionner à la racine de junior.utbm.fr et sous `/Website/` sur GitHub Pages. Seule `404.html` utilise des chemins absolus (`/assets/...`).
3. Committer et pousser sur `main`.

## Mise en ligne

À chaque push sur `main` :

- `.github/workflows/deploy.yml` publie l'aperçu sur GitHub Pages ;
- `.github/workflows/deploy-production.yml` demande à junior.utbm.fr de récupérer le dernier commit.

junior.utbm.fr est un WordPress hébergé par l'UTBM. Le plugin [Junior Static Deploy](https://github.com/JuniorUTBM/junior-static-deploy) y télécharge ce dépôt et copie les fichiers à la racine du site. WordPress reste installé en arrière-plan pour son administration uniquement. En cas de problème : WordPress → Réglages → Site statique.
