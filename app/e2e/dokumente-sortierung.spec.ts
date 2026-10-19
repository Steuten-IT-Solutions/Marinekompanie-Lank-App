import { expect, test } from '@playwright/test';
import type { DocCategory, DocumentInfo } from '../src/api';
import { docGroupKey, groupDocuments, type DocSort } from '../src/documents';

// Reine Unit-Tests für Gruppieren und Sortieren der Dokumente (ohne Browser und ohne WordPress).

let nextId = 1;
function doc(title: string, date: string, category_id: number | null = 1): DocumentInfo {
  const id = nextId++;
  return {
    id,
    title,
    category_id,
    date,
    visibility: 'mitglieder',
    ext: 'pdf',
    kind: 'pdf',
    mime: 'application/pdf',
    size: 1000,
    version: '1',
    url: `/app/dokument/${id}`,
    updated_at: '2026-01-01 00:00:00',
  };
}

// Reihenfolge der Kategorien ist festgelegt (sort), nicht alphabetisch.
const categories: DocCategory[] = [
  { id: 2, title: 'Satzung', sort: 1 },
  { id: 1, title: 'Protokolle', sort: 2 },
];

const titles = (docs: DocumentInfo[]) => docs.map((d) => d.title);

test('Dokumente: alle vier Sortierungen innerhalb einer Kategorie, Reihenfolge der Kategorien bleibt', () => {
  const docs = [
    doc('Protokoll 10', '2026-03-01'),
    doc('Protokoll 2', '2026-05-01'),
    doc('Ärzteliste', '2025-01-01'),
    doc('Beitragsordnung', '2026-04-01'),
    doc('Satzung', '2020-01-01', 2),
    doc('Ehrenordnung', '2024-01-01', 2),
  ];
  const expected: Record<DocSort, string[]> = {
    'name-asc': ['Ärzteliste', 'Beitragsordnung', 'Protokoll 2', 'Protokoll 10'],
    'name-desc': ['Protokoll 10', 'Protokoll 2', 'Beitragsordnung', 'Ärzteliste'],
    'date-desc': ['Protokoll 2', 'Beitragsordnung', 'Protokoll 10', 'Ärzteliste'],
    'date-asc': ['Ärzteliste', 'Protokoll 10', 'Beitragsordnung', 'Protokoll 2'],
  };
  for (const [sort, order] of Object.entries(expected) as [DocSort, string[]][]) {
    const groups = groupDocuments(docs, categories, sort);
    expect(groups.map((g) => g.title), sort).toEqual(['Satzung', 'Protokolle']);
    expect(titles(groups[1].docs), sort).toEqual(order);
  }
  expect(titles(groupDocuments(docs, categories, 'name-asc')[0].docs)).toEqual(['Ehrenordnung', 'Satzung']);
  expect(titles(groupDocuments(docs, categories, 'date-desc')[0].docs)).toEqual(['Ehrenordnung', 'Satzung']);
  // Ohne Angabe gilt „Stand neu → alt“.
  expect(titles(groupDocuments(docs, categories)[1].docs)).toEqual(expected['date-desc']);
});

test('Dokumente: gleicher Stand wird nach Name sortiert, Umlaute wie Grundbuchstaben', () => {
  const docs = [doc('Übungsplan', '2026-01-01'), doc('apfel', '2026-01-01'), doc('Unterlagen', '2026-01-01'), doc('Zeitplan', '2025-01-01')];
  expect(titles(groupDocuments(docs, categories, 'date-desc')[0].docs)).toEqual(['apfel', 'Übungsplan', 'Unterlagen', 'Zeitplan']);
  expect(titles(groupDocuments(docs, categories, 'date-asc')[0].docs)).toEqual(['Zeitplan', 'apfel', 'Übungsplan', 'Unterlagen']);
});

test('Dokumente: ohne oder mit unbekannter Kategorie unter „Weitere Dokumente“ am Ende', () => {
  const docs = [doc('Zettel', '2026-01-01', null), doc('Alt', '2026-01-01', 99), doc('Protokoll', '2026-01-01', 1)];
  const groups = groupDocuments(docs, categories, 'name-asc');
  expect(groups.map((g) => g.title)).toEqual(['Protokolle', 'Weitere Dokumente']);
  expect(titles(groups[1].docs)).toEqual(['Alt', 'Zettel']);
  expect(docGroupKey(99, categories)).toBe('ohne');
  expect(docGroupKey(null, categories)).toBe('ohne');
  expect(docGroupKey(1, categories)).toBe('1');

  // Ganz ohne Kategorien: eine einzige Gruppe ohne Titel (wird nicht eingeklappt).
  const single = groupDocuments(docs, [], 'name-desc');
  expect(single).toHaveLength(1);
  expect(single[0].title).toBe('');
  expect(titles(single[0].docs)).toEqual(['Zettel', 'Protokoll', 'Alt']);
});
