import { useState } from "react";
import { api } from "@/lib/api/builder";
import { describeError, isApiError } from "@/lib/api/errors";

export function LoginForm({ onDone }: { onDone: () => void }) {
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  return (
    <form
      className="login-form"
      onSubmit={async e => {
        e.preventDefault();
        setBusy(true);
        setError("");
        try {
          await api.login(password);
          setPassword("");
          onDone();
        } catch (err) {
          setError(
            isApiError(err) && err.kind === "unauthorized"
              ? "That password was not accepted."
              : isApiError(err) && err.status === 429
                ? "Too many attempts. Try again in a few minutes."
                : describeError(err)
          );
        } finally {
          setBusy(false);
        }
      }}
    >
      <div className="field">
        <label htmlFor="rk-pass">
          <span>Editor password</span>
        </label>
        <input
          id="rk-pass"
          data-autofocus
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={e => setPassword(e.target.value)}
          aria-invalid={!!error}
          aria-describedby={error ? "rk-pass-err" : undefined}
        />
        {error && (
          <small id="rk-pass-err" className="form-error" role="alert">
            {error}
          </small>
        )}
      </div>
      <button className="save-btn" disabled={busy || !password}>
        {busy ? "Signing in…" : "Sign in"}
      </button>
    </form>
  );
}

export function LoginScreen({ onDone }: { onDone: () => void }) {
  return (
    <main className="center-screen">
      <div className="card-panel">
        <span className="eyebrow">RK / BUILDER</span>
        <h1>Sign in to edit</h1>
        <p className="muted">
          Editing writes to WordPress through this server. Credentials never
          reach your browser.
        </p>
        <LoginForm onDone={onDone} />
      </div>
    </main>
  );
}
