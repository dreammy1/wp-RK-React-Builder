type ZodIssueLike = {
  code: string;
  message: string;
  minimum?: number | bigint;
  maximum?: number | bigint;
  origin?: string;
};

/** Editor-friendly wording for schema issues; the schema stays the single source of truth. */
export function friendlyMessage(issue: ZodIssueLike): string {
  const str = issue.origin === "string";
  switch (issue.code) {
    case "too_small":
      if (str && Number(issue.minimum) <= 1) return "This field is required";
      return str
        ? `Must be at least ${issue.minimum} characters`
        : `Must be at least ${issue.minimum}`;
    case "too_big":
      return str
        ? `Must be at most ${issue.maximum} characters`
        : `Must be at most ${issue.maximum}`;
    case "invalid_type":
      return "This field is required";
    case "invalid_value":
      return "Choose one of the allowed values";
    default:
      return issue.message;
  }
}
