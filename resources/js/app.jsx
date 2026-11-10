import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

const SLOT_MIN = 30;
const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
const allTz = ['UTC', ...(Intl.supportedValuesOf?.('timeZone') ?? []).filter((z) => z !== 'UTC')];

async function api(path, opts = {}) {
    const res = await fetch('/api' + path, {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        ...opts,
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
        // Laravel validation errors have {errors:{field:[msg]}}; business rules have {error,message}.
        const detail = body.errors ? Object.values(body.errors).flat().join(' ') : body.message;
        throw new Error(detail || `Request failed (${res.status})`);
    }
    return body;
}

const fmtTime = (ms, tz) =>
    new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: tz }).format(ms);
const today = (tz) => new Intl.DateTimeFormat('en-CA', { timeZone: tz }).format(new Date()); // YYYY-MM-DD

const COLORS = ['var(--c0)', 'var(--c1)', 'var(--c2)', 'var(--c3)', 'var(--c4)'];
const shiftDate = (d, n) => { const x = new Date(d + 'T00:00:00Z'); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };
const toMin = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };

function App() {
    const [tz, setTz] = useState(browserTz);
    const [date, setDate] = useState(today(browserTz));
    const [sandboxes, setSandboxes] = useState([]);
    const [day, setDay] = useState(null);
    const [error, setError] = useState('');
    const [ok, setOk] = useState('');
    const [form, setForm] = useState({ sandbox_id: '', start_time: '10:00', end_time: '11:00', analyst_name: '', sample_ref: '' });

    useEffect(() => { api('/sandboxes').then((s) => { setSandboxes(s); setForm((f) => ({ ...f, sandbox_id: s[0]?.id ?? '' })); }).catch((e) => setError(e.message)); }, []);

    const load = useCallback(() =>
        api(`/bookings?date=${date}&timezone=${encodeURIComponent(tz)}`).then((d) => { setDay(d); setError((e) => (e.startsWith('Unknown IANA') ? '' : e)); }).catch((e) => setError(e.message)), [date, tz]);
    useEffect(() => { load(); }, [load]);
    useEffect(() => { if (!ok) return; const t = setTimeout(() => setOk(''), 4000); return () => clearTimeout(t); }, [ok]);

    // Rows are real instants from the server's day bounds, so DST days (23h/25h) render correctly.
    const slots = useMemo(() => {
        if (!day) return [];
        const out = [];
        for (let t = Date.parse(day.day_start_utc); t < Date.parse(day.day_end_utc); t += SLOT_MIN * 60000) out.push(t);
        return out;
    }, [day]);

    const isMaint = (t) => new Date(t).getUTCHours() === 2; // 02:00-03:00 UTC
    const cellBooking = (sid, t) =>
        day?.bookings.find((b) => b.sandbox_id === sid && Date.parse(b.start_utc) < t + SLOT_MIN * 60000 && Date.parse(b.end_utc) > t);

    const mins = toMin(form.end_time) - toMin(form.start_time);
    const durText = mins > 0 ? `${Math.floor(mins / 60)}h ${mins % 60}m` : 'End must be after start';
    const durBad = mins <= 0 || mins > 120;

    async function submit(e) {
        e.preventDefault();
        setError(''); setOk('');
        try {
            await api('/bookings', { method: 'POST', body: JSON.stringify({ ...form, sandbox_id: Number(form.sandbox_id), date, timezone: tz }) });
            setOk('Booking created.');
        } catch (err) { setError(err.message); }
        load();
    }

    async function cancel(b) {
        if (!confirm(`Cancel the booking by ${b.analyst_name}?`)) return;
        setError(''); setOk('');
        try { await api(`/bookings/${b.id}`, { method: 'DELETE' }); setOk('Booking cancelled.'); } catch (err) { setError(err.message); }
        load();
    }

    function pick(sid, t) { // click an empty cell to prefill the form
        const start = fmtTime(t, tz);
        const endMs = t + 60 * 60000;
        setForm((f) => ({ ...f, sandbox_id: sid, start_time: start, end_time: fmtTime(endMs, tz) }));
    }

    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
    const idx = (sid) => sandboxes.findIndex((s) => s.id === sid);

    return (
        <>
            <header className="topbar">
                <div className="logo">🛡️</div>
                <div>
                    <h1>Malware Analysis Sandbox Booking</h1>
                    <p>Reserve an isolated detonation sandbox. One analyst per sandbox at a time.</p>
                </div>
                <div className="rules">
                    <span className="chip">Max 2 h</span>
                    <span className="chip">Maintenance 02:00-03:00 UTC</span>
                    <span className="chip">Stored in UTC</span>
                </div>
            </header>
            <main>
                <div className="toolbar">
                    <button className="btn icon" onClick={() => setDate(shiftDate(date, -1))} aria-label="Previous day">‹</button>
                    <input type="date" value={date} onChange={(e) => e.target.value && setDate(e.target.value)} />
                    <button className="btn icon" onClick={() => setDate(shiftDate(date, 1))} aria-label="Next day">›</button>
                    <button className="btn" onClick={() => setDate(today(tz))}>Today</button>
                    <span className="spacer" />
                    <span className="tzlabel">Time zone</span>
                    <select value={tz} onChange={(e) => setTz(e.target.value)}>
                        {[...new Set([browserTz, ...allTz])].map((z) => <option key={z}>{z}</option>)}
                    </select>
                </div>

                {error && <div className="toast err"><span>{error}</span><button onClick={() => setError('')}>×</button></div>}
                {ok && <div className="toast ok"><span>{ok}</span><button onClick={() => setOk('')}>×</button></div>}

                <div className="layout">
                    <form className="card form" onSubmit={submit}>
                        <h2>Book a sandbox</h2>
                        <label>Sandbox
                            <select value={form.sandbox_id} onChange={set('sandbox_id')}>
                                {sandboxes.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.os})</option>)}
                            </select>
                        </label>
                        <label>Date ({tz})<input type="date" value={date} onChange={(e) => e.target.value && setDate(e.target.value)} required /></label>
                        <div className="row2">
                            <label>Start<input type="time" step="900" value={form.start_time} onChange={set('start_time')} required /></label>
                            <label>End<input type="time" step="900" value={form.end_time} onChange={set('end_time')} required /></label>
                        </div>
                        <div className={'dur' + (durBad ? ' bad' : '')}>Duration: {durText}{mins > 120 ? ' (max 2h)' : ''}</div>
                        <label>Analyst name<input value={form.analyst_name} onChange={set('analyst_name')} maxLength={100} placeholder="e.g. Asha Kumar" required /></label>
                        <label>Sample reference<input value={form.sample_ref} onChange={set('sample_ref')} maxLength={200} placeholder="sha256 or ticket id" required /></label>
                        <button type="submit" className="btn primary">Book sandbox</button>
                        <div className="fine">Tip: click any free cell in the grid to prefill sandbox and start time. Times are in {tz}; the server converts to UTC.</div>
                    </form>

                    <div className="card grid-card">
                        <div className="legend">
                            {sandboxes.map((s, i) => <span key={s.id}><i style={{ background: COLORS[i % 5] }} />{s.name}</span>)}
                            <span><i className="hatch" />Maintenance</span>
                        </div>
                        <div className="grid-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th className="corner">{tz.split('/').pop().replace('_', ' ')}</th>
                                        {sandboxes.map((s, i) => <th key={s.id}><span className="dot" style={{ background: COLORS[i % 5] }} />{s.name}<small>{s.os}</small></th>)}
                                    </tr>
                                </thead>
                                <tbody>
                                    {slots.map((t, r) => (
                                        <tr key={t} className={fmtTime(t, tz).endsWith(':00') ? 'hour' : ''}>
                                            <td className="time">{fmtTime(t, tz).endsWith(':00') ? fmtTime(t, tz) : ''}</td>
                                            {sandboxes.map((s) => {
                                                const b = cellBooking(s.id, t);
                                                if (b) {
                                                    const first = Date.parse(b.start_utc) >= t || r === 0;
                                                    const last = Date.parse(b.end_utc) <= t + SLOT_MIN * 60000 || r === slots.length - 1;
                                                    return (
                                                        <td key={s.id} className={'booked' + (first ? ' first' : '') + (last ? ' last' : '')} style={{ background: COLORS[idx(s.id) % 5] }}
                                                            title={`${b.analyst_name} · ${b.sample_ref}\n${fmtTime(Date.parse(b.start_utc), tz)}-${fmtTime(Date.parse(b.end_utc), tz)} (${tz})`}>
                                                            {first && <>{b.analyst_name}<span className="sub">{b.sample_ref}</span><button onClick={() => cancel(b)}>Cancel</button></>}
                                                        </td>
                                                    );
                                                }
                                                return isMaint(t)
                                                    ? <td key={s.id} className="maint">{new Date(t).getUTCMinutes() === 0 ? 'maintenance' : ''}</td>
                                                    : <td key={s.id} className="free" onClick={() => pick(s.id, t)} title="Click to book from here" />;
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!day && <div className="empty">Loading…</div>}
                        </div>
                    </div>
                </div>
            </main>
        </>
    );
}

createRoot(document.getElementById('root')).render(<App />);
