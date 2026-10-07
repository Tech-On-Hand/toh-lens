import { Form } from '@inertiajs/react';
import { Sprout } from 'lucide-react';
import { useMemo, useState } from 'react';
import SchoolClassController from '@/actions/App/Http/Controllers/Admin/SchoolClassController';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CbcLevel, SchoolClassRow, SchoolOption } from '@/types';

/** "Blue, green; Red" -> ["Blue", "green", "Red"] (distinct ignoring case), like the server does. */
function parseStreams(text: string): string[] {
    const seen = new Map<string, string>();
    for (const part of text.split(/[,\n;]+/)) {
        const stream = part.trim().replace(/\s+/g, ' ');
        if (stream && !seen.has(stream.toLowerCase())) seen.set(stream.toLowerCase(), stream);
    }
    return [...seen.values()];
}

export function SeedCbcPanel({
    schools,
    classes,
    levels,
    onDone,
}: {
    schools: SchoolOption[];
    classes: Pick<SchoolClassRow, 'name' | 'school'>[];
    levels: CbcLevel[];
    onDone: () => void;
}) {
    const [schoolId, setSchoolId] = useState(schools.length === 1 ? String(schools[0].id) : '');
    const [ticked, setTicked] = useState<string[]>(levels.filter((l) => l.default).map((l) => l.key));
    const [streamsText, setStreamsText] = useState('');

    // What would be created, and what the school already has, so the button can say so before anyone clicks it.
    const { toCreate, existing } = useMemo(() => {
        const taken = new Set(classes.filter((c) => String(c.school?.id) === schoolId).map((c) => c.name.toLowerCase()));
        const streams = parseStreams(streamsText);
        let create = 0;
        let skip = 0;
        for (const level of levels.filter((l) => ticked.includes(l.key))) {
            for (const grade of level.grades) {
                for (const stream of streams.length ? streams : [null]) {
                    const name = stream ? `${grade} ${stream}` : grade;
                    if (taken.has(name.toLowerCase())) skip++;
                    else create++;
                }
            }
        }
        return { toCreate: create, existing: skip };
    }, [classes, levels, schoolId, ticked, streamsText]);

    const toggle = (key: string) => setTicked((current) => (current.includes(key) ? current.filter((k) => k !== key) : [...current, key]));

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Sprout className="text-(--toh-green) size-4.5" />
                    Seed Kenya (CBC) classes
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-muted-foreground text-sm">
                    Creates the standard Competency Based Curriculum classes for a school in one go. Classes the school already has are left exactly as they
                    are, so it is safe to run again.
                </p>

                <Form {...SchoolClassController.seedCbc.form()} onSuccess={onDone} className="space-y-4">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2 sm:max-w-sm">
                                <Label htmlFor="seed_school_id">School</Label>
                                <NativeSelect id="seed_school_id" name="school_id" value={schoolId} onChange={(e) => setSchoolId(e.target.value)} required>
                                    <option value="" disabled>
                                        Select a school
                                    </option>
                                    {schools.map((school) => (
                                        <option key={school.id} value={school.id}>
                                            {school.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={errors.school_id} />
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-1 text-sm font-medium">Levels</legend>
                                <div className="flex flex-wrap gap-x-6 gap-y-2">
                                    {levels.map((level) => (
                                        <label key={level.key} className="flex items-start gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                name="levels[]"
                                                value={level.key}
                                                checked={ticked.includes(level.key)}
                                                onChange={() => toggle(level.key)}
                                                className="mt-1"
                                            />
                                            <span>
                                                <span className="font-medium">{level.label}</span>
                                                <span className="text-muted-foreground block text-xs">{level.grades.join(', ')}</span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.levels ?? errors['levels.0']} />
                            </fieldset>

                            <div className="grid gap-2 sm:max-w-md">
                                <Label htmlFor="seed_streams">Streams (optional)</Label>
                                <Input
                                    id="seed_streams"
                                    name="streams"
                                    value={streamsText}
                                    onChange={(e) => setStreamsText(e.target.value)}
                                    placeholder="Blue, Green, Red"
                                />
                                <p className="text-muted-foreground text-xs">
                                    Separate names with commas. Leave empty for one class per grade, such as "Grade 4". With streams you get "Grade 4 Blue", "Grade 4 Green" and so on.
                                </p>
                                <InputError message={errors.streams} />
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button disabled={processing || !schoolId || toCreate === 0}>
                                    <Sprout /> {toCreate === 0 ? 'Nothing to create' : `Create ${toCreate} ${toCreate === 1 ? 'class' : 'classes'}`}
                                </Button>
                                <Button type="button" variant="ghost" onClick={onDone}>
                                    Cancel
                                </Button>
                                {schoolId && existing > 0 && (
                                    <span className="text-muted-foreground text-sm">
                                        {existing} already {existing === 1 ? 'exists' : 'exist'} and will be skipped.
                                    </span>
                                )}
                            </div>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}
