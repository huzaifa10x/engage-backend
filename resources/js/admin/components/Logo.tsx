import { BRAND_LIME, WORDMARK } from "./brand-artwork";

/** The Engage logo. The admin shows it on its dark sidebar and sign-in backdrop, so the ink defaults to white. */
export function Logo({
    className = "h-7 w-auto",
    ink = "#FFFFFF",
}: {
    className?: string;
    ink?: string;
}) {
    return (
        <svg
            viewBox={WORDMARK.viewBox}
            role="img"
            aria-label="10X Engage"
            className={className}
        >
            <path fill={ink} d={WORDMARK.ink} />
            <circle fill={BRAND_LIME} {...WORDMARK.dot} />
        </svg>
    );
}
