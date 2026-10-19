import { Head } from "@inertiajs/react";
import type { ReactNode } from "react";
import { Logo } from "../components/Logo";

/** Sign-in screens: the dark sidebar colour becomes the whole backdrop, card centred. */
export default function AuthLayout({
    title,
    subtitle,
    children,
}: {
    title: string;
    subtitle?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="grid min-h-screen place-items-center bg-side px-4 py-10">
            <Head title={title} />
            <div className="w-full max-w-[400px]">
                <div className="mb-6">
                    <Logo className="h-8 w-auto" />
                    <div className="mt-2 text-[12.5px] text-side-muted">
                        Platform administration
                    </div>
                </div>
                <div className="rounded-card bg-white p-6 shadow-lg">
                    <h1 className="text-[19px] font-bold tracking-tight">
                        {title}
                    </h1>
                    {subtitle && (
                        <p className="mt-1 text-[13.5px] text-muted">
                            {subtitle}
                        </p>
                    )}
                    <div className="mt-5">{children}</div>
                </div>
            </div>
        </div>
    );
}
