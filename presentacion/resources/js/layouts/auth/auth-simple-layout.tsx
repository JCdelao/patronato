import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh items-center justify-center bg-linear-to-br from-[#0b3229] to-[#0d5c46] p-4 md:p-10">
            <main className="w-full max-w-md rounded-2xl bg-card p-8 shadow-xl sm:p-10">
                <p className="text-xs font-bold tracking-[0.12em] text-primary uppercase">
                    Patronato Digital
                </p>
                <h1 className="mt-2 text-3xl font-bold tracking-tight">
                    {title}
                </h1>
                {description && (
                    <p className="mt-2 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
                <div className="mt-8">{children}</div>
            </main>
        </div>
    );
}
