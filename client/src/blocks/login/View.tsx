import type { ViewProps } from "@/render/ViewProps";
import type { LoginProps } from "./schema";

/**
 * Static markup only: the real form action, the redirect target, the error message, the "already signed in" state and
 * the logo / business details are filled in by the server when the page is served (includes/login.php), so this
 * matches the PHP output exactly.
 */
export function LoginView({ props }: ViewProps<LoginProps>) {
  const split = props.layout === "split";
  const card = (
    <div className="site-login-card">
      {props.showBrand === true && (
        <div className="site-login-brand" data-rk-login-brand=""></div>
      )}
      <h2>{props.heading}</h2>
      {props.intro && <p>{props.intro}</p>}
      <div className="site-login-msg" role="alert" hidden></div>
      <form
        className="site-login-form"
        method="post"
        data-rk-login=""
        onSubmit={e => e.preventDefault()}
      >
        <label>
          <span>Email or username</span>
          <input
            type="text"
            name="log"
            autoComplete="username"
            required={true}
          />
        </label>
        <label>
          <span>Password</span>
          <input
            type="password"
            name="pwd"
            autoComplete="current-password"
            required={true}
          />
        </label>
        {props.remember !== false && (
          <label className="site-login-check">
            <input type="checkbox" name="rememberme" value="forever" />
            <span>Remember me</span>
          </label>
        )}
        <button type="submit" className="site-login-btn">
          {props.button}
        </button>
      </form>
      {props.forgot !== false && (
        <p className="site-login-links">
          <a href="#rk-lostpassword">Forgot your password?</a>
        </p>
      )}
      {props.showDetails === true && (
        <div className="site-login-details" data-rk-login-details=""></div>
      )}
    </div>
  );
  if (!split) return <section className="site-login">{card}</section>;
  return (
    <section
      className={
        props.side === "right"
          ? "site-login is-split side-right"
          : "site-login is-split"
      }
    >
      <div className="site-login-media">
        {props.imageUrl && (
          <img
            className="site-login-img"
            src={props.imageUrl}
            alt={props.imageAlt ?? ""}
            decoding="async"
          />
        )}
      </div>
      <div className="site-login-main">{card}</div>
    </section>
  );
}
