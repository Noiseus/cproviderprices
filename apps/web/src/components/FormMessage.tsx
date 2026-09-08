export function FormMessage({
  message,
  error = false,
}: {
  message?: string;
  error?: boolean;
}) {
  if (!message) return null;
  return (
    <p
      className={error ? "form-message error" : "form-message"}
      role={error ? "alert" : "status"}
    >
      {message}
    </p>
  );
}
