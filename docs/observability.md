# Observability

The bundle exposes tenant lifecycle, resolution, PostgreSQL RLS, and rejected-header events through Symfony EventDispatcher. The default subscribers provide structured logs and vendor-neutral counters.

## Default metrics

| Metric | Labels |
| --- | --- |
| `tenant_resolution_total` | `resolver`, `status` |
| `tenant_rls_apply_total` | `status` |
| `tenant_header_rejected_total` | none |

Default metric labels are deliberately bounded. Tenant identifiers, names, failure reasons, raw header names, exception messages, and arbitrary event context are never labels. Applications adding custom labels must define a finite allow-list and a cardinality budget.

Implement `MetricsAdapterInterface` and alias it in the container to export the counters. The default null adapter has no external dependency.

## Default logging

Successful resolution and context lifecycle logs may contain the stable tenant identifier because logs are access-controlled diagnostic records. Default failure logs include only the fields needed to classify the operation:

```text
Tenant successfully resolved: tenant_id, resolver
Tenant resolution failed: resolver
Tenant context started/ended: tenant_id
Tenant RLS application succeeded/failed: tenant_id
Tenant header rejected by allow-list: no untrusted fields
```

The subscriber does not copy arbitrary resolution context, failure reasons, raw database errors, or rejected header names into logs. If an application records additional fields, it must sanitize them, avoid credentials and personal data, define retention, and restrict access.

## Events

The public event objects retain diagnostic data for explicit application subscribers:

- `TenantResolvedEvent`
- `TenantResolutionFailedEvent`
- `TenantContextStartedEvent`
- `TenantContextEndedEvent`
- `TenantRlsAppliedEvent`
- `TenantHeaderRejectedEvent`

Receiving an event does not make every property safe for logs or metrics. Treat event context and error strings as untrusted and potentially confidential.

## Operational rules

- Alert on bounded status/resolver series.
- Correlate a tenant through protected logs or traces, not metric labels.
- Never expose tenant names or mail metadata merely for diagnostics.
- Test custom adapters with malicious and high-cardinality input.
