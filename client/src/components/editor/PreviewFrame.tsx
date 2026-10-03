import { useEffect, useRef, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";

/**
 * Draws its children inside an iframe, so the page's media queries answer to the frame's width
 * (a 390px frame behaves like a phone) instead of to the editor window's width.
 * The frame has a fixed height and scrolls inside, like a device screen: a page that sizes itself
 * to the viewport (100vh) would otherwise grow with an auto-sized frame, without end.
 */
export function PreviewFrame({
  title,
  children,
}: {
  title: string;
  children: ReactNode;
}) {
  const ref = useRef<HTMLIFrameElement>(null);
  const [doc, setDoc] = useState<Document | null>(null);

  useEffect(() => {
    const d = ref.current?.contentDocument;
    if (!d) return;
    d.open();
    d.write(
      '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body></body></html>'
    );
    d.close();
    document.head
      .querySelectorAll('link[rel="stylesheet"], style')
      .forEach(n => d.head.appendChild(n.cloneNode(true)));
    d.body.style.margin = "0";
    // The preview is not a browser: links inside it go nowhere.
    const stop = (e: Event) => {
      if ((e.target as Element | null)?.closest?.("a")) e.preventDefault();
    };
    d.addEventListener("click", stop);
    setDoc(d);
    return () => {
      d.removeEventListener("click", stop);
    };
  }, []);

  return (
    <>
      <iframe ref={ref} title={title} className="preview-frame" />
      {doc && createPortal(children, doc.body)}
    </>
  );
}
