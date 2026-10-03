import type { ReactNode } from "react";
import { ExternalLink } from "lucide-react";
import type { PageSchemaSettings } from "@/lib/schema/api";

const PAGE_TYPES = [
  ["WebPage", "Standard page"],
  ["AboutPage", "About page"],
  ["ContactPage", "Contact page"],
  ["CollectionPage", "Collection / listing page"],
  ["ProfilePage", "Profile page"],
] as const;

/** One switch with its explanation; the fields for it open underneath while it is on. */
function Row({
  id,
  title,
  help,
  on,
  onChange,
  children,
}: {
  id: string;
  title: string;
  help: string;
  on: boolean;
  onChange: (v: boolean) => void;
  children?: ReactNode;
}) {
  return (
    <div className={`schema-row${on ? " on" : ""}`}>
      <div className="schema-row-head">
        <span>
          <label htmlFor={id}>
            <strong>{title}</strong>
          </label>
          <small id={`${id}-help`}>{help}</small>
        </span>
        <input
          id={id}
          type="checkbox"
          checked={on}
          aria-describedby={`${id}-help`}
          onChange={e => onChange(e.target.checked)}
        />
      </div>
      {on && children && <div className="schema-row-body">{children}</div>}
    </div>
  );
}

function Field({
  id,
  label,
  hint,
  children,
}: {
  id: string;
  label: string;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div className="field">
      <label htmlFor={id}>
        <span>{label}</span>
      </label>
      {children}
      {hint && <small className="muted">{hint}</small>}
    </div>
  );
}

/** Which JSON-LD the page prints in its head. Values such as the title, address and image come from the page itself. */
export function SchemaPanel({
  value,
  onChange,
  pageUrl,
}: {
  value: PageSchemaSettings;
  onChange: (v: PageSchemaSettings) => void;
  /** The live address, when the page is published (the validators read it from the web). */
  pageUrl: string | null;
}) {
  const v = value;
  const set = (patch: Partial<PageSchemaSettings>) =>
    onChange({ ...v, ...patch });
  const enc = pageUrl ? encodeURIComponent(pageUrl) : "";
  return (
    <section className="schema-panel" aria-labelledby="schema-h">
      <h2 id="schema-h">Schema markup (JSON-LD)</h2>
      <p className="muted">
        Tells search engines what this page is. The title, address, description,
        image and site name are filled in from the page, and the business
        details come from Site &amp; SEO.
      </p>

      <Field id="sc-type" label="Page type">
        <select
          id="sc-type"
          value={v.pageType}
          onChange={e => set({ pageType: e.target.value })}
        >
          {PAGE_TYPES.map(([id, label]) => (
            <option key={id} value={id}>
              {label} ({id})
            </option>
          ))}
        </select>
      </Field>

      <Row
        id="sc-crumb"
        title="Breadcrumb trail"
        help="BreadcrumbList: Home › … › this page. Not added to the front page."
        on={v.breadcrumb}
        onChange={breadcrumb => set({ breadcrumb })}
      />
      <Row
        id="sc-biz"
        title="About this business"
        help="Links the page to your LocalBusiness details (needs an address under Site & SEO). Otherwise it points to the Organization."
        on={v.business}
        onChange={business => set({ business })}
      />
      <Row
        id="sc-article"
        title="Article"
        help="Blog posts and news: headline, image, dates and author."
        on={v.article.on}
        onChange={on => set({ article: { ...v.article, on } })}
      >
        <Field id="sc-article-type" label="Kind of article">
          <select
            id="sc-article-type"
            value={v.article.type}
            onChange={e =>
              set({ article: { ...v.article, type: e.target.value } })
            }
          >
            <option value="Article">Article</option>
            <option value="BlogPosting">Blog post (BlogPosting)</option>
            <option value="NewsArticle">News article (NewsArticle)</option>
          </select>
        </Field>
      </Row>
      <Row
        id="sc-service"
        title="Service"
        help="A service your business offers, provided by your organization."
        on={v.service.on}
        onChange={on => set({ service: { ...v.service, on } })}
      >
        <Field
          id="sc-service-name"
          label="Service name"
          hint="Leave empty to use the page title."
        >
          <input
            id="sc-service-name"
            type="text"
            maxLength={120}
            value={v.service.name}
            onChange={e =>
              set({ service: { ...v.service, name: e.target.value } })
            }
          />
        </Field>
        <Field
          id="sc-service-desc"
          label="Service description"
          hint="Leave empty to use the search description."
        >
          <textarea
            id="sc-service-desc"
            rows={2}
            maxLength={400}
            value={v.service.description}
            onChange={e =>
              set({ service: { ...v.service, description: e.target.value } })
            }
          />
        </Field>
      </Row>
      <Row
        id="sc-product"
        title="Product"
        help="A product with a price. Nothing is printed until a price is set."
        on={v.product.on}
        onChange={on => set({ product: { ...v.product, on } })}
      >
        <div className="form-grid">
          <Field
            id="sc-product-name"
            label="Product name"
            hint="Empty uses the page title."
          >
            <input
              id="sc-product-name"
              type="text"
              maxLength={120}
              value={v.product.name}
              onChange={e =>
                set({ product: { ...v.product, name: e.target.value } })
              }
            />
          </Field>
          <Field id="sc-product-brand" label="Brand">
            <input
              id="sc-product-brand"
              type="text"
              maxLength={80}
              value={v.product.brand}
              onChange={e =>
                set({ product: { ...v.product, brand: e.target.value } })
              }
            />
          </Field>
          <Field id="sc-product-price" label="Price" hint="Numbers only: 49.50">
            <input
              id="sc-product-price"
              type="text"
              inputMode="decimal"
              value={v.product.price}
              onChange={e =>
                set({ product: { ...v.product, price: e.target.value } })
              }
            />
          </Field>
          <Field id="sc-product-cur" label="Currency" hint="USD, EUR, GBP…">
            <input
              id="sc-product-cur"
              type="text"
              maxLength={3}
              value={v.product.currency}
              onChange={e =>
                set({
                  product: {
                    ...v.product,
                    currency: e.target.value.toUpperCase(),
                  },
                })
              }
            />
          </Field>
          <Field id="sc-product-avail" label="Availability">
            <select
              id="sc-product-avail"
              value={v.product.availability}
              onChange={e =>
                set({ product: { ...v.product, availability: e.target.value } })
              }
            >
              <option value="InStock">In stock</option>
              <option value="OutOfStock">Out of stock</option>
              <option value="PreOrder">Pre-order</option>
              <option value="LimitedAvailability">Limited availability</option>
            </select>
          </Field>
        </div>
      </Row>
      <Row
        id="sc-faq"
        title="FAQ"
        help="Questions and answers, as shown in search results. Add them only if they also appear on the page."
        on={v.faq.on}
        onChange={on => set({ faq: { ...v.faq, on } })}
      >
        <Field
          id="sc-faq-items"
          label="Questions and answers"
          hint="One per line: Question | Answer. Up to 20."
        >
          <textarea
            id="sc-faq-items"
            className="code-area"
            rows={5}
            value={v.faq.items}
            placeholder={"How long does staining take? | Usually two days."}
            onChange={e => set({ faq: { ...v.faq, items: e.target.value } })}
          />
        </Field>
      </Row>
      <Row
        id="sc-review"
        title="Rating (AggregateRating)"
        help="Your average rating, attached to the Product, Service or business above. Use real figures only."
        on={v.review.on}
        onChange={on => set({ review: { ...v.review, on } })}
      >
        <div className="form-grid">
          <Field id="sc-rating" label="Average rating" hint="1 to 5, e.g. 4.8">
            <input
              id="sc-rating"
              type="text"
              inputMode="decimal"
              value={v.review.rating}
              onChange={e =>
                set({ review: { ...v.review, rating: e.target.value } })
              }
            />
          </Field>
          <Field id="sc-rcount" label="Number of reviews">
            <input
              id="sc-rcount"
              type="text"
              inputMode="numeric"
              value={v.review.count}
              onChange={e =>
                set({ review: { ...v.review, count: e.target.value } })
              }
            />
          </Field>
        </div>
      </Row>

      <div className="schema-validate">
        {pageUrl ? (
          <>
            <a
              className="top-btn"
              href={`https://validator.schema.org/#url=${enc}`}
              target="_blank"
              rel="noreferrer"
            >
              <ExternalLink size={14} aria-hidden="true" /> Validate Schema
            </a>
            <a
              className="top-btn"
              href={`https://search.google.com/test/rich-results?url=${enc}`}
              target="_blank"
              rel="noreferrer"
            >
              <ExternalLink size={14} aria-hidden="true" /> Google Rich Results
              Test
            </a>
            <small className="muted">
              Save first; the tools read the live page.
            </small>
          </>
        ) : (
          <small className="muted">
            Publish the page to check its schema with the validators.
          </small>
        )}
      </div>
    </section>
  );
}
