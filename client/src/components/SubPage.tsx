import { useEffect, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { ArrowLeft } from "lucide-react";
import { Modal } from "./Modal";

/**
 * A full-page form inside the dashboard: it takes over the content area (the screen behind it is hidden, not
 * unmounted, so nothing is lost) and has a Back link. Same props as Modal; outside the dashboard (the editor) it
 * falls back to a pop-up.
 */
export function SubPage({
  title,
  onClose,
  children,
  wide,
  dismissable = true,
  backLabel = "Back",
}: {
  title: string;
  onClose: () => void;
  children: ReactNode;
  wide?: boolean;
  dismissable?: boolean;
  backLabel?: string;
}) {
  const [host] = useState(() => document.querySelector(".dash-main"));
  useEffect(() => {
    if (!host) return;
    host.classList.add("has-subpage");
    window.scrollTo(0, 0);
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape" && dismissable) onClose();
    };
    window.addEventListener("keydown", onKey);
    return () => {
      host.classList.remove("has-subpage");
      window.removeEventListener("keydown", onKey);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  if (!host)
    return (
      <Modal
        title={title}
        onClose={onClose}
        wide={wide}
        dismissable={dismissable}
      >
        {children}
      </Modal>
    );
  return createPortal(
    <section
      className={`subpage ${wide ? "wide" : ""}`}
      aria-labelledby="subpage-title"
    >
      <div className="subpage-head">
        {dismissable && (
          <button className="subpage-back" onClick={onClose}>
            <ArrowLeft size={15} aria-hidden="true" /> {backLabel}
          </button>
        )}
        <h1 id="subpage-title">{title}</h1>
      </div>
      <div className="subpage-body">{children}</div>
    </section>,
    host
  );
}
