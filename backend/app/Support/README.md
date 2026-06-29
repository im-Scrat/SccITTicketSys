# Support

Shared, cross-cutting code that is **not** specific to any single domain.
Namespaced `App\Support`.

```
app/Support/
├── Concerns/   # reusable traits mixed into models/services
├── DTOs/       # shared data transfer objects
├── Enums/      # shared enums (e.g. global statuses, roles)
└── Traits/     # general-purpose traits
```

Keep this lean — if something belongs to one domain, put it under that domain
instead (see [`App\Domains`](../Domains)).
