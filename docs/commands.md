# Commandes CLI disponibles

## `tenant:create`

Crée un nouveau tenant :

```bash
php bin/console tenant:create genlis "Ville de Genlis"
```

> Par défaut, la commande utilisera la classe configurée dans `tenant_entity`.

## `tenant:list`

Affiche tous les tenants :

```bash
php bin/console tenant:list
```
Affiche un tableau avec l’ID, le slug, et le nom (si disponible).



