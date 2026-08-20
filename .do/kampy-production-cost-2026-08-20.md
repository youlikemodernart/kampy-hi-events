# Kampy Hi.Events production cost ceiling

Dated: 2026-08-20
Approved recurring ceiling: USD 85/month

## Starting topology

| Resource | Selected minimum | Monthly list price |
|---|---:|---:|
| App Platform | `apps-s-1vcpu-2gb`, one container | USD 25 |
| Managed PostgreSQL | single node, 1 GiB RAM | USD 15 |
| Managed Valkey | single node, 1 GiB RAM | USD 15 |
| Spaces Standard | first two buckets share one subscription | USD 5 |
| **Expected starting total** | | **USD 60/month** |

Headroom under the approved ceiling: USD 25/month.

The source template must attach exactly one existing or newly created minimum PostgreSQL cluster and one minimum Valkey cluster. The provider quote and selected plan IDs must be read back before creation. Spaces is one USD 5 subscription shared across the public and private buckets, not USD 5 per bucket.

The 4 GiB shared App Platform plan is USD 50/month. With both databases and Spaces it would make the total USD 85/month. Do not add another paid app component, database standby, dedicated egress IP, or other paid resource without recalculating the total and staying inside the approved ceiling.

## Usage-sensitive costs

- App Platform overage bandwidth beyond the plan allowance: USD 0.02/GiB.
- Spaces includes 250 GiB storage and 1,024 GiB outbound transfer across buckets. Additional storage is USD 0.02/GiB/month; additional outbound transfer is USD 0.01/GiB.
- Database additional storage is separately metered.
- Stripe, Postmark, tax, refunds, disputes, and email volume are outside this DigitalOcean infrastructure subtotal.

## Availability posture

The USD 15 PostgreSQL and Valkey plans are single-node entry plans. They are not high availability. This is an explicit launch tradeoff under the USD 85 ceiling. Upgrade only with a new cost and availability decision.

## Sources

- DigitalOcean App Platform pricing, last verified by DigitalOcean 2026-07-13: https://docs.digitalocean.com/products/app-platform/details/pricing/
- Managed PostgreSQL pricing, last verified by DigitalOcean 2025-06-17: https://docs.digitalocean.com/products/databases/postgresql/details/pricing/
- Managed Valkey pricing, last verified by DigitalOcean 2026-05-11: https://docs.digitalocean.com/products/databases/valkey/details/pricing/
- Spaces pricing, last verified by DigitalOcean 2026-07-13: https://docs.digitalocean.com/products/spaces/details/pricing/

Provider control-panel pricing and the final generated App Spec readback supersede this dated packet if they conflict.
