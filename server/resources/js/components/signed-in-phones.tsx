import { router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { shortDate } from '@/lib/format';

export type Phone = {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
};

/**
 * The phones signed in with the app. Signing them out ends their sync and pushes at
 * once; they have to sign in again with the password (and the 2FA code, if it's on).
 */
export default function SignedInPhones({ phones }: { phones: Phone[] }) {
    const signOut = (url: string, question: string) => {
        if (window.confirm(question)) {
            router.delete(url, { preserveScroll: true });
        }
    };

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Signed-in phones"
                description="Phones that use the Home Helper app with your account. Sign out any you don't recognise, or all of them after losing a phone."
            />
            {phones.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No phones are signed in.
                </p>
            ) : (
                <>
                    <ul className="divide-y divide-border rounded-md border text-sm">
                        {phones.map((p) => (
                            <li
                                key={p.id}
                                className="flex items-center justify-between gap-4 px-3 py-2"
                            >
                                <div>
                                    <p className="font-medium">{p.name}</p>
                                    <p className="text-muted-foreground">
                                        {p.last_used_at
                                            ? `last used ${shortDate(p.last_used_at.slice(0, 10))}`
                                            : 'never used'}
                                        {p.created_at &&
                                            ` · signed in ${shortDate(p.created_at.slice(0, 10))}`}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        signOut(
                                            `/settings/phones/${p.id}`,
                                            `Sign out "${p.name}"?`,
                                        )
                                    }
                                >
                                    Sign out
                                </Button>
                            </li>
                        ))}
                    </ul>
                    <Button
                        variant="destructive"
                        onClick={() =>
                            signOut(
                                '/settings/phones',
                                'Sign out every phone? Each one will need your password again.',
                            )
                        }
                    >
                        Sign out all phones
                    </Button>
                </>
            )}
        </div>
    );
}
