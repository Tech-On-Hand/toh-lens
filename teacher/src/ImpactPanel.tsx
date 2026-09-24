import { useCallback, useEffect, useMemo, useState } from "react";
import * as api from "./api";
import type { Classroom, Impact } from "./types";

function isoDate(date: Date): string {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 10);
}

function daysAgo(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() - days);
  return isoDate(date);
}

const PRESETS = [
  { label: "Last 30 days", from: () => daysAgo(29) },
  { label: "Last 90 days", from: () => daysAgo(89) },
  { label: "Last 12 months", from: () => daysAgo(364) },
];

function longDate(iso: string): string {
  return new Date(`${iso}T12:00:00`).toLocaleDateString([], { day: "numeric", month: "long", year: "numeric" });
}

function number(value: number): string {
  return value.toLocaleString();
}

/** A few plain sentences a donor or board member can read without the charts. */
function summary(impact: Impact, outcomes: string): string {
  const { computers, students, usage, availability, tools } = impact;
  const lines: string[] = [];
  lines.push(
    `Between ${longDate(impact.period.from)} and ${longDate(impact.period.to)}, ${impact.scope.name} had ${number(computers.enrolled)} computer${computers.enrolled === 1 ? "" : "s"} in service.`,
  );
  lines.push(
    `${number(students.reached)} student${students.reached === 1 ? "" : "s"}${students.on_roster > 0 ? ` of ${number(students.on_roster)} (${students.reach_percent}%)` : ""} signed in and used them, across ${number(usage.sessions)} sessions and about ${number(Math.round(usage.signed_in_hours))} hours.`,
  );
  if (computers.enrolled > 0) lines.push(`${number(computers.used)} of ${number(computers.enrolled)} computers were used at least once.`);
  if (availability.used_percent !== null) {
    lines.push(`On the school days a computer was switched on, a student used it ${availability.used_percent}% of the time.`);
  }
  if (tools.apps.length > 0) lines.push(`The most-used programs were ${tools.apps.slice(0, 4).map((app) => app.name).join(", ")}.`);
  if (outcomes.trim()) lines.push("", outcomes.trim());
  return lines.join("\n");
}

function Tile({ value, label, hint }: { value: string; label: string; hint?: string }) {
  return (
    <div className="impact-tile">
      <strong>{value}</strong>
      <span>{label}</span>
      {hint && <small>{hint}</small>}
    </div>
  );
}

export default function ImpactPanel({ classroom, onClose }: { classroom: Classroom; onClose: () => void }) {
  const orgId = classroom.school.organization_id;
  const [scope, setScope] = useState<"school" | "organization">("school");
  const [from, setFrom] = useState(daysAgo(29));
  const [to, setTo] = useState(daysAgo(0));
  const [impact, setImpact] = useState<Impact | null>(null);
  const [notice, setNotice] = useState("");
  const [copied, setCopied] = useState(false);
  const storageKey = `impact-outcomes:${scope}:${scope === "school" ? classroom.school.id : orgId}`;
  const [outcomes, setOutcomes] = useState("");

  useEffect(() => {
    try { setOutcomes(localStorage.getItem(storageKey) ?? ""); } catch { setOutcomes(""); }
  }, [storageKey]);

  const saveOutcomes = (value: string) => {
    setOutcomes(value);
    try { localStorage.setItem(storageKey, value); } catch { /* keeps working without saving */ }
  };

  const load = useCallback(() => {
    setNotice("");
    api.getImpact(scope === "school" ? classroom.school.id : null, scope === "organization" ? orgId : null, from, to)
      .then(setImpact)
      .catch((reason) => { setImpact(null); setNotice(String(reason)); });
  }, [scope, classroom.school.id, orgId, from, to]);

  useEffect(() => { load(); }, [load]);

  const maxHours = useMemo(() => Math.max(1, ...(impact?.weekly.map((week) => week.hours) ?? [1])), [impact]);

  const copy = async () => {
    if (!impact) return;
    try {
      await navigator.clipboard.writeText(summary(impact, outcomes));
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setNotice("Could not copy. Select the summary text and copy it by hand.");
    }
  };

  return (
    <div className="panel-backdrop" onClick={onClose}>
      <aside className="panel report impact-print" onClick={(event) => event.stopPropagation()}>
        <header className="no-print">
          <div>
            <p className="eyebrow">{impact?.scope.name ?? classroom.school.name}</p>
            <h2>Impact report</h2>
          </div>
          <div className="row">
            <button className="ghost" onClick={() => void copy()} disabled={!impact}>{copied ? "Copied" : "Copy summary"}</button>
            <button className="ghost" onClick={() => window.print()} disabled={!impact}>Print / save as PDF</button>
            <button className="ghost" onClick={onClose}>Close</button>
          </div>
        </header>
        {notice && <p className="notice">{notice}</p>}

        <div className="row report-range no-print">
          {orgId !== null && (
            <label>Cover <select value={scope} onChange={(event) => setScope(event.target.value as "school" | "organization")}>
              <option value="school">{classroom.school.name}</option>
              <option value="organization">Every school in the organization</option>
            </select></label>
          )}
          {PRESETS.map((preset) => (
            <button key={preset.label} className={from === preset.from() && to === daysAgo(0) ? "ghost active" : "ghost"} onClick={() => { setFrom(preset.from()); setTo(daysAgo(0)); }}>{preset.label}</button>
          ))}
          <label>From <input type="date" value={from} max={to} onChange={(event) => event.target.value && setFrom(event.target.value)} /></label>
          <label>To <input type="date" value={to} min={from} max={daysAgo(0)} onChange={(event) => event.target.value && setTo(event.target.value)} /></label>
        </div>

        {impact && (
          <>
            <h1 className="print-only">{impact.scope.name}: how the computers are being used</h1>
            <p className="muted">{longDate(impact.period.from)} to {longDate(impact.period.to)} ({impact.period.school_days} school days)</p>

            <section className="impact-tiles">
              <Tile value={impact.students.on_roster > 0 ? `${impact.students.reached} of ${impact.students.on_roster}` : number(impact.students.reached)} label="students used a computer" hint={impact.students.reach_percent !== null ? `${impact.students.reach_percent}% of the roster` : undefined} />
              <Tile value={number(Math.round(impact.usage.signed_in_hours))} label="hours of use" hint={`${number(impact.usage.sessions)} sessions`} />
              <Tile value={`${impact.computers.used} of ${impact.computers.enrolled}`} label="computers used" hint={impact.computers.out_of_service > 0 ? `${impact.computers.out_of_service} not seen for 2+ weeks` : undefined} />
              <Tile
                value={impact.availability.used_percent !== null ? `${impact.availability.used_percent}%` : "Not yet"}
                label="of school days used, when switched on"
                hint={impact.availability.used_percent === null ? "needs a few weeks of history" : `${number(impact.availability.computer_days_used)} of ${number(impact.availability.computer_days_on)} computer-days`}
              />
            </section>

            <h3>Hours of use each week</h3>
            <div className="impact-bars" role="img" aria-label="Hours of computer use per week">
              {impact.weekly.map((week) => (
                <div key={week.week_start} className="impact-bar" title={`Week of ${longDate(week.week_start)}: ${week.hours} hours, ${week.students} students, ${week.sessions} sessions`}>
                  <div style={{ height: `${Math.round((week.hours / maxHours) * 100)}%` }} />
                  <small>{new Date(`${week.week_start}T12:00:00`).toLocaleDateString([], { day: "numeric", month: "short" })}</small>
                </div>
              ))}
            </div>

            {impact.schools.length > 1 && (
              <>
                <h3>By school</h3>
                <table className="report-table">
                  <thead><tr><th>School</th><th>Computers used</th><th>Students</th><th>Sessions</th><th>Hours</th></tr></thead>
                  <tbody>
                    {impact.schools.map((school) => (
                      <tr key={school.name}><td>{school.name}</td><td>{school.computers_used} of {school.computers}</td><td>{school.students}</td><td>{number(school.sessions)}</td><td>{number(Math.round(school.hours))}</td></tr>
                    ))}
                  </tbody>
                </table>
              </>
            )}

            <h3>What they used</h3>
            {impact.tools.apps.length === 0 && impact.tools.sites.length === 0 ? (
              <p className="muted">Nothing to list yet. A program or website is only shown once at least {impact.tools.min_students} different students have used it, so no one student can be picked out.</p>
            ) : (
              <div className="impact-lists">
                <div>
                  <strong>Programs</strong>
                  {impact.tools.apps.map((app) => <div key={app.name}>{app.name} <small>{app.hours} h</small></div>)}
                </div>
                <div>
                  <strong>Websites</strong>
                  {impact.tools.sites.map((site) => <div key={site.domain}>{site.domain} <small>{number(site.visits)} visits</small></div>)}
                </div>
              </div>
            )}
            <p className="muted">Only programs and websites used by at least {impact.tools.min_students} different students are listed.</p>

            {(impact.computers.never_used > 0 || impact.computers.out_of_service > 0) && (
              <p className="muted no-print">Needs attention: {impact.computers.never_used} computer(s) enrolled for over a week and never used; {impact.computers.out_of_service} not heard from for two weeks.</p>
            )}

            <h3 className={outcomes.trim() ? undefined : "no-print"}>Results from the school</h3>
            <textarea className="impact-outcomes no-print" rows={4} value={outcomes} onChange={(event) => saveOutcomes(event.target.value)} placeholder="Add what the numbers can't show: teacher quotes, test results, attendance, a story. It is kept on this computer and included when you copy or print." />
            {outcomes.trim() && <p className="print-only impact-outcomes-print">{outcomes}</p>}

            <h3>Summary</h3>
            <p className="impact-summary">{summary(impact, "")}</p>

            <ul className="muted impact-notes">{impact.notes.map((note) => <li key={note}>{note}</li>)}</ul>
            <p className="muted">Generated {new Date(impact.generated_at).toLocaleString()}. No student is named anywhere in this report.</p>
          </>
        )}
      </aside>
    </div>
  );
}
