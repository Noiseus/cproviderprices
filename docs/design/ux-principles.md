# UX Principles

- **Fast perceived performance:** show useful structure or feedback promptly and keep common navigation responsive.
- **Avoid unnecessary work:** minimize full-page reloads, duplicate requests, oversized payloads, and fetching data the current view does not need.
- **Cache deliberately:** cache stable or safely scoped information with explicit freshness and invalidation rules. Never trade tenant isolation or price accuracy for speed.
- **Communicate state:** provide clear loading, success, empty, stale, partial, and error states with recovery actions where possible.
- **Responsive and accessible:** support practical screen sizes, keyboard access, visible focus, semantic structure, readable contrast, and understandable labels.
- **Operations-first:** optimize the initial desktop experience for restaurant and hospitality purchasing workflows without coupling domain contracts to desktop-only interactions.
- **Efficient comparison:** minimize clicks and context switching when searching, selecting, comparing, and reviewing supplier options. Keep units, case sizes, timestamps, and uncertainty visible.
- **Scale large lists:** supplier and product lists require efficient server-aware search, filtering, sorting, and pagination; use virtualization when measured list size and interaction needs justify it.
- **Mobile-ready contracts:** future mobile support should reuse APIs and domain semantics, while mobile UI design remains a later concern.

Performance behavior must be measured once implementation exists. Specific caching strategies and thresholds belong to the relevant implementation prompts.
