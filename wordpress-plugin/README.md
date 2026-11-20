# Junior Static Deploy (plugin WordPress)

junior.utbm.fr tourne sur un WordPress hébergé par l'UTBM. Ce plugin y publie le site statique de ce
dépôt : à chaque push sur `main`, le workflow `.github/workflows/deploy-production.yml` appelle
`https://junior.utbm.fr/index.php?rest_route=/junior-deploy/v1/sync`, et le plugin télécharge le dernier
commit de `main` depuis GitHub puis copie les fichiers à la racine du site.

WordPress reste installé en arrière-plan : `/wp-admin/` fonctionne toujours, mais les visiteurs voient
les pages HTML du dépôt.

## Installation (une seule fois)

1. Zipper le dossier : `cd wordpress-plugin && zip -r junior-static-deploy.zip junior-static-deploy`
2. WordPress → Extensions → Ajouter → Téléverser une extension → choisir le zip → Activer.
3. Réglages → Site statique → **Déployer maintenant**. Le résultat s'affiche sous « Dernière opération ».

## Garde-fous

- Seul le dernier commit de `main` de `JuniorUTBM/Website` peut être publié ; l'URL d'appel n'a donc pas besoin de secret.
- Le plugin n'écrit ni ne supprime jamais rien dans `wp-admin/`, `wp-includes/`, `wp-content/`, ni les fichiers racine de WordPress.
- Après chaque déploiement, il teste le site ; en cas d'erreur serveur, il restaure l'ancien `.htaccess`.
- Revenir à WordPress : Réglages → Site statique → **Retirer le site statique**.
