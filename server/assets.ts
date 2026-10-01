import { createHash } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));

/** Locates a file whether running from source (tsx), the esbuild bundle in dist/, or tests. */
function find(candidates: string[]): string {
  for (const c of candidates) if (existsSync(c)) return c;
  throw new Error(
    `Required asset not found. Looked in: ${candidates.join(", ")}`
  );
}

export function loadSiteCss(): { css: string; version: string } {
  const file = find([
    path.resolve(here, "assets/site.css"),
    path.resolve(here, "../client/src/styles/site.css"),
    path.resolve(here, "../../client/src/styles/site.css"),
  ]);
  const css = readFileSync(file, "utf8");
  return {
    css,
    version: createHash("sha1").update(css).digest("hex").slice(0, 8),
  };
}

export const RUM_JS = `(()=>{
var s=0,l=0,sent=false,u='/api/telemetry';
function send(o){try{o.path=location.pathname;navigator.sendBeacon(u,new Blob([JSON.stringify(o)],{type:'application/json'}))}catch(e){}}
try{new PerformanceObserver(function(a){var e=a.getEntries();l=e[e.length-1].startTime}).observe({type:'largest-contentful-paint',buffered:true})}catch(e){}
try{new PerformanceObserver(function(a){a.getEntries().forEach(function(e){if(!e.hadRecentInput)s+=e.value})}).observe({type:'layout-shift',buffered:true})}catch(e){}
addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden'&&!sent){sent=true;send({type:'vitals',lcp:Math.round(l),cls:Math.round(s*1000)/1000})}});
addEventListener('error',function(e){var t=e.target;if(t&&t.tagName==='IMG'){send({type:'image_error',detail:String(t.currentSrc||t.src).slice(0,200)})}else{send({type:'js_error',detail:String(e.message).slice(0,200)})}},true);
})();`;
