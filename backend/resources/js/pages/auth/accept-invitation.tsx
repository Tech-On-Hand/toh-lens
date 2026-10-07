import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function AcceptInvitation({ token }: { token: string }) {
    return (
        <>
            <Head title="Accept invitation" />

            <Form
                action="/invitations/accept"
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="token">Invitation code</Label>
                            <Input
                                id="token"
                                name="token"
                                defaultValue={token}
                                required
                                autoFocus={!token}
                                autoComplete="off"
                                placeholder="Paste the code you were given"
                            />
                            <InputError message={errors.token} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Your name</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus={!!token}
                                autoComplete="name"
                                placeholder="Full name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Choose a password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                autoComplete="new-password"
                                placeholder="At least 12 characters"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                required
                                autoComplete="new-password"
                                placeholder="Confirm password"
                            />
                            <InputError message={errors.password_confirmation} />
                        </div>

                        <Button
                            type="submit"
                            className="mt-2 w-full"
                            disabled={processing}
                            data-test="accept-invitation-button"
                        >
                            {processing && <Spinner />}
                            Create my account
                        </Button>

                        <p className="text-muted-foreground text-center text-sm">
                            Already set up? <TextLink href="/login">Log in</TextLink>
                        </p>
                    </div>
                )}
            </Form>
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Accept your invitation',
    description:
        'Enter the code from your administrator and choose a password. If you already have an account, your existing password stays the same.',
};
