# TODO Multi-Tenant Bundle

## ✅ Pour la V0

- [x] Context
- [x] Resolver
- [x] DoctrineFilter
- [x] ConnectionSwitcher
- [ ] Commandes `tenant:migrate`, `tenant:create-db`
- [ ] Autoload Doctrine entity paths par tenant (multi-db)
- [ ] Support `TenantSettings`
- [ ] Cache per tenant
- [ ] Paramètres dynamiques (mailer_dsn, messenger transport)
- [ ] Gestion du dossier `/var/tenant/` isolé
- [ ] Documentation centralisée

## 🔜 Pour la V1

- [ ] ConnectionFactory propre
- [ ] `TenantUserInterface` + guard adapter
- [ ] UX/AdminBundle pour gérer les tenants
- [ ] Fallback + "superadmin" tenant global