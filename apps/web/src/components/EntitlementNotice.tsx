export function EntitlementNotice({
  kind,
  title,
  children,
}: {
  kind: "limit" | "unavailable" | "suspended" | "error";
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className={`entitlement-notice ${kind}`} role="status">
      <h2>{title}</h2>
      <div>{children}</div>
    </section>
  );
}
