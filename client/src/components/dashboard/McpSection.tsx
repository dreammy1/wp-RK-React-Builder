import { useEffect, useState } from "react";
import { Check, Copy, ExternalLink, KeyRound, Trash2 } from "lucide-react";
import { api } from "@/lib/api/builder";
import { describeIssues } from "@/lib/api/errors";
import type { McpAdmin, McpLevel } from "@/lib/schema/api";
import { fmtWhen } from "./Overview";

const LEVELS: { id: McpLevel; name: string; help: string }[] = [
  {
    id: "read",
    name: "Read only",
    help: "The AI can look at pages, content, media and settings, and change nothing.",
  },
  {
    id: "write",
    name: "Read and write",
    help: "Also create and edit pages, content, SEO, templates and settings, and publish. Trashing is recoverable. Nothing is deleted for good.",
  },
  {
    id: "full",
    name: "Full access",
    help: "Also delete images, templates and reusable blocks for good, and change the code printed on every page. Use it for a task, then lower it.",
  },
];
const RISK: Record<McpLevel, string> = {
  read: "read",
  write: "write",
  full: "full access",
};

function CopyBlock({ text, label }: { text: string; label: string }) {
  const [done, setDone] = useState(false);
  return (
    <div className="mcp-code">
      <pre aria-label={label}>{text}</pre>
      <button
        className="top-btn"
        onClick={() => {
          void navigator.clipboard?.writeText(text);
          setDone(true);
          setTimeout(() => setDone(false), 1500);
        }}
      >
        {done ? (
          <Check size={14} aria-hidden="true" />
        ) : (
          <Copy size={14} aria-hidden="true" />
        )}{" "}
        {done ? "Copied" : "Copy"}
      </button>
    </div>
  );
}

type Client = "code" | "desktop" | "other" | "curl";

/** Setup text for each assistant, with the key filled in (or a placeholder before one is made). */
function snippet(client: Client, endpoint: string, key: string): string {
  if (client === "code")
    return `claude mcp add --transport http rk-builder ${endpoint} \\\n  --header "X-RK-API-Key: ${key}"`;
  if (client === "desktop")
    return JSON.stringify(
      {
        mcpServers: {
          "rk-builder": {
            command: "npx",
            args: [
              "-y",
              "mcp-remote",
              endpoint,
              "--header",
              "X-RK-API-Key:${RK_KEY}",
            ],
            env: { RK_KEY: key },
          },
        },
      },
      null,
      2
    );
  if (client === "curl")
    return `curl -s -X POST '${endpoint}' \\\n  -H 'X-RK-API-Key: ${key}' -H 'Content-Type: application/json' \\\n  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'`;
  return JSON.stringify(
    {
      mcpServers: {
        "rk-builder": { url: endpoint, headers: { "X-RK-API-Key": key } },
      },
    },
    null,
    2
  );
}

/** Setup for the WordPress Application Password route (HTTP Basic). */
function basicSnippet(endpoint: string, user: string): string {
  return `claude mcp add --transport http rk-builder ${endpoint} \\\n  --header "Authorization: Basic $(printf '%s' '${user || "YOUR_USERNAME"}:APP_PASSWORD' | base64)"`;
}

/** Lets AI assistants (Claude, Cursor, …) do dashboard work through the Model Context Protocol. Off until switched on here. */
export function McpSection() {
  const [d, setD] = useState<McpAdmin | null>(null);
  const [client, setClient] = useState<Client>("code");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [keyName, setKeyName] = useState("Claude");
  const [keyLevel, setKeyLevel] = useState<McpLevel>("write");
  const [fresh, setFresh] = useState<string | null>(null);

  useEffect(() => {
    api
      .getMcp()
      .then(setD)
      .catch(e => setError(describeIssues(e)));
  }, []);

  const save = (
    patch: { enabled?: boolean; level?: McpLevel; clearLog?: boolean },
    msg: string
  ) => {
    setBusy(true);
    setError("");
    setNote("");
    // show the choice at once; the server's answer replaces it
    setD(prev =>
      prev
        ? {
            ...prev,
            settings: {
              enabled: patch.enabled ?? prev.settings.enabled,
              level: patch.level ?? prev.settings.level,
            },
          }
        : prev
    );
    api
      .setMcp(patch)
      .then(r => {
        setD(r);
        setNote(msg);
      })
      .catch(e => {
        setError(describeIssues(e));
        void api.getMcp().then(setD);
      })
      .finally(() => setBusy(false));
  };

  if (!d)
    return error ? (
      <div className="notice error" role="alert">
        {error}
      </div>
    ) : (
      <p className="muted">Loading…</p>
    );

  const on = d.settings.enabled;
  const groups = [...new Set(d.tools.map(t => t.group))];

  return (
    <>
      <header className="dash-head">
        <div>
          <h1>AI &amp; MCP</h1>
          <p className="muted">
            Let an AI assistant such as Claude or Cursor do dashboard work for
            you: build and edit pages, write SEO text, manage content, media and
            settings. It connects through the Model Context Protocol (MCP) and
            has exactly the rights of the account you sign it in with.
          </p>
        </div>
      </header>
      {note && (
        <p className="notice info inline" role="status">
          {note}
        </p>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}

      <section className="dash-card" aria-labelledby="mcp-access">
        <h2 id="mcp-access">Access</h2>
        <div className="field check">
          <label>
            <input
              type="checkbox"
              checked={on}
              disabled={busy}
              onChange={e =>
                save(
                  { enabled: e.target.checked },
                  e.target.checked
                    ? "MCP is on. AI assistants can now connect."
                    : "MCP is off. Nothing can connect."
                )
              }
            />{" "}
            <span>
              Allow AI assistants to connect
              <small className="muted">
                {" "}
                Off by default. While it is off the address below answers
                “disabled”.
              </small>
            </span>
          </label>
        </div>
        <fieldset className="field kind-pick" disabled={busy}>
          <legend>What it may do</legend>
          {LEVELS.map(l => (
            <label
              key={l.id}
              className={`kind-card ${d.settings.level === l.id ? "on" : ""}`}
            >
              <input
                type="radio"
                name="mcp-level"
                checked={d.settings.level === l.id}
                onChange={() =>
                  save({ level: l.id }, `Access level: ${l.name}.`)
                }
              />
              <strong>{l.name}</strong>
              <small>{l.help}</small>
            </label>
          ))}
        </fieldset>
        <p className="muted">
          Every request is checked like a request from the dashboard: the same
          permissions, the same strict validation, the same revisions and
          conflict checks. Publishing and trashing are logged below.
        </p>
      </section>

      <section className="dash-card" aria-labelledby="mcp-connect">
        <h2 id="mcp-connect">Connect an AI assistant</h2>
        <ol className="mcp-steps">
          <li>
            <strong>Make a connection key.</strong> One key per assistant. It
            acts as your account, and its level can only be equal to or lower
            than the access level above.
            <div className="form-grid">
              <div className="field">
                <label htmlFor="mcp-key-name">
                  <span>Name</span>
                </label>
                <input
                  id="mcp-key-name"
                  type="text"
                  maxLength={60}
                  value={keyName}
                  onChange={e => setKeyName(e.target.value)}
                />
              </div>
              <div className="field">
                <label htmlFor="mcp-key-level">
                  <span>Key level</span>
                </label>
                <select
                  id="mcp-key-level"
                  value={keyLevel}
                  onChange={e => setKeyLevel(e.target.value as McpLevel)}
                >
                  {LEVELS.map(l => (
                    <option key={l.id} value={l.id}>
                      {l.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>
            <button
              className="save-btn"
              disabled={busy || !keyName.trim()}
              onClick={() => {
                setBusy(true);
                setError("");
                setNote("");
                api
                  .createMcpKey(keyName.trim(), keyLevel)
                  .then(r => {
                    setD(r);
                    setFresh(r.created?.token ?? null);
                    setNote("Key created. Copy it now.");
                  })
                  .catch(e => setError(describeIssues(e)))
                  .finally(() => setBusy(false));
              }}
            >
              <KeyRound size={14} aria-hidden="true" /> Create key
            </button>
            {fresh && (
              <div className="mcp-fresh" role="status">
                <strong>Copy this key now. It is shown only once.</strong>
                <CopyBlock text={fresh} label="New connection key" />
                <small className="muted">
                  Lost it? Revoke it below and make another.
                </small>
              </div>
            )}
            {d.keys.length > 0 && (
              <ul className="dash-list mcp-keys" aria-label="Connection keys">
                {d.keys.map(k => (
                  <li key={k.id}>
                    <span>
                      <strong>{k.name}</strong> <code>{k.prefix}…</code>
                    </span>
                    <span className="muted">
                      {RISK[k.level]} · {k.user} ·{" "}
                      {k.used ? `used ${fmtWhen(k.used)}` : "never used"}
                    </span>
                    <button
                      className="top-btn danger"
                      disabled={busy}
                      aria-label={`Revoke ${k.name}`}
                      onClick={() => {
                        if (
                          !window.confirm(
                            `Revoke “${k.name}”? Anything using this key stops working.`
                          )
                        )
                          return;
                        setBusy(true);
                        api
                          .revokeMcpKey(k.id)
                          .then(r => {
                            setD(r);
                            setNote(`Revoked “${k.name}”.`);
                          })
                          .catch(e => setError(describeIssues(e)))
                          .finally(() => setBusy(false));
                      }}
                    >
                      <Trash2 size={14} aria-hidden="true" /> Revoke
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </li>
          <li>
            <strong>Address of this site&apos;s MCP server</strong>
            <CopyBlock text={d.endpoint} label="MCP address" />
          </li>
          <li>
            <strong>Add it to your assistant.</strong>{" "}
            {fresh ? (
              "Your new key is filled in."
            ) : (
              <>
                Replace <code>YOUR_KEY</code> with a key from step 1.
              </>
            )}
            <div className="chip-row" role="group" aria-label="Assistant">
              {(
                [
                  ["code", "Claude Code"],
                  ["desktop", "Claude Desktop"],
                  ["other", "Cursor and others"],
                  ["curl", "Test in a terminal"],
                ] as const
              ).map(([id, label]) => (
                <button
                  key={id}
                  className={`chip ${client === id ? "on" : ""}`}
                  aria-pressed={client === id}
                  onClick={() => setClient(id)}
                >
                  {label}
                </button>
              ))}
            </div>
            <CopyBlock
              text={snippet(client, d.endpoint, fresh ?? "YOUR_KEY")}
              label={`Setup for ${client}`}
            />
            <small className="muted">
              Some hosts drop the standard <code>Authorization</code> header, so
              the key travels in <code>X-RK-API-Key</code>. A{" "}
              <code>Bearer</code> header works as well.
            </small>
          </li>
        </ol>
        <details className="mcp-group">
          <summary>Use a WordPress Application Password instead</summary>
          <div className="mcp-steps">
            <p className="muted">
              For clients that only support HTTP Basic sign-in. Open{" "}
              <a href={d.profileUrl}>
                your profile <ExternalLink size={12} aria-hidden="true" />
              </a>
              , add an Application Password named “RK Builder AI” and copy it
              once. It acts as your account with the access level above.
              {!d.passwordsOk && (
                <span className="form-error" role="alert">
                  {" "}
                  Application Passwords are not available on this site (they
                  need HTTPS, and some plugins turn them off).
                </span>
              )}
            </p>
            <CopyBlock
              text={basicSnippet(d.endpoint, d.username)}
              label="Setup with an Application Password"
            />
          </div>
        </details>
      </section>

      <section className="dash-card" aria-labelledby="mcp-tools">
        <h2 id="mcp-tools">What it can do ({d.tools.length} tools)</h2>
        <p className="muted">
          Tools above the current access level are listed but not offered to the
          assistant.
        </p>
        {groups.map(g => (
          <details key={g} className="mcp-group">
            <summary>
              {g}{" "}
              <small className="muted">
                {d.tools.filter(t => t.group === g).length}
              </small>
            </summary>
            <ul className="dash-list">
              {d.tools
                .filter(t => t.group === g)
                .map(t => (
                  <li key={t.name} className={t.enabled ? "" : "mcp-off"}>
                    <div className="mcp-tool">
                      <strong>{t.title}</strong> <code>{t.name}</code>
                      <small className="muted">{t.description}</small>
                    </div>
                    <span
                      className={`badge ${t.risk === "read" ? "publish" : t.risk === "write" ? "draft" : "warn"}`}
                    >
                      {RISK[t.risk]}
                    </span>
                    {!t.enabled && (
                      <small className="muted">needs a higher level</small>
                    )}
                  </li>
                ))}
            </ul>
          </details>
        ))}
      </section>

      <section className="dash-card" aria-labelledby="mcp-log">
        <h2 id="mcp-log">Recent activity</h2>
        {d.log.length === 0 ? (
          <p className="muted">No requests yet.</p>
        ) : (
          <>
            <ul className="dash-list mcp-log">
              {d.log.map((l, i) => (
                <li key={`${l.t}-${i}`}>
                  <span className="muted">{fmtWhen(l.t)}</span>
                  <code>{l.tool}</code>
                  <span>{l.via ? `${l.via} · ${l.user}` : l.user}</span>
                  <span className={`badge ${l.ok ? "publish" : "warn"}`}>
                    {l.ok ? "done" : "refused or failed"}
                  </span>
                </li>
              ))}
            </ul>
            <div className="dialog-actions">
              <button
                className="top-btn"
                disabled={busy}
                onClick={() => save({ clearLog: true }, "Activity cleared.")}
              >
                <Trash2 size={14} aria-hidden="true" /> Clear
              </button>
            </div>
          </>
        )}
      </section>
    </>
  );
}
