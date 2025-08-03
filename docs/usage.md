# Usage

## Résolution automatique du tenant

Le bundle fournit un `RequestListener` qui intercepte chaque requête HTTP.

Il utilise le `TenantResolverInterface` configuré pour :
- extraire le tenant à partir de l’URL ou du sous-domaine
- injecter le tenant courant dans le `TenantContext`
- dispatcher un `TenantResolvedEvent` que vous pouvez écouter

## Écouter le tenant résolu

```php
use Zhortein\MultiTenantBundle\Event\TenantResolvedEvent;

class MySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            TenantResolvedEvent::class => 'onTenantResolved',
        ];
    }

    public function onTenantResolved(TenantResolvedEvent $event): void
    {
        $tenant = $event->getTenant();
        // Faites ce que vous voulez (logger, config dynamique, etc.)
    }
}
