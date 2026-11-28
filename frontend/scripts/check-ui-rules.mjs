// UI guardrails from the design system (docs/project-structure.md, Frontend):
// colors come from tokens and styling from shared classes, so components
// carry no hex colors and no static inline styles; Bangla and English
// translations stay complete and in use. Runs with `npm run check`.
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const sourceDir = join(root, 'src');

function files(dir, extensions) {
    return readdirSync(dir, { recursive: true, withFileTypes: true })
        .filter(
            (entry) =>
                entry.isFile() &&
                extensions.some((extension) => entry.name.endsWith(extension)),
        )
        .map((entry) => join(entry.parentPath, entry.name));
}

// A hex color: #rgb, #rgba, #rrggbb or #rrggbbaa, not a slot shorthand such
// as `#add="…"` or `<template #bed>` and not part of a word or an entity.
const hexColor =
    /(?<![&\w/])#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})\b(?!\s*[=>])/gi;
// A static style attribute; a `:style` binding for data-driven sizes is allowed.
const staticStyle = /(?<![:\w-])style\s*=/g;

const rules = [
    {
        files: files(sourceDir, ['.vue', '.ts']),
        pattern: hexColor,
        message: 'hex color; add a token in src/styles/tokens.css and use it',
    },
    {
        files: files(sourceDir, ['.vue']),
        pattern: staticStyle,
        message: 'inline style; use a shared class in src/styles/app.css',
    },
];

const problems = [];
for (const rule of rules) {
    for (const file of rule.files) {
        readFileSync(file, 'utf8')
            .split('\n')
            .forEach((line, index) => {
                for (const match of line.matchAll(rule.pattern)) {
                    problems.push(
                        `${relative(root, file)}:${index + 1}: ${match[0]} — ${rule.message}`,
                    );
                }
            });
    }
}

// Translations: the same keys and placeholders in every language, no empty
// text, and no key the code never uses. Keys built at run time, such as
// `preferences.theme.${theme}`, count as used through their prefix.
const localeDir = join(sourceDir, 'locales');
const languages = ['bn', 'en'];
const dictionaries = Object.fromEntries(
    languages.map((language) => [
        language,
        JSON.parse(readFileSync(join(localeDir, `${language}.json`), 'utf8')),
    ]),
);
const placeholders = (text) =>
    [...text.matchAll(/\{\w+\}/g)]
        .map((match) => match[0])
        .sort((left, right) => left.localeCompare(right))
        .join();
const allKeys = new Set(
    languages.flatMap((language) => Object.keys(dictionaries[language])),
);
for (const key of allKeys) {
    for (const language of languages) {
        const text = dictionaries[language][key];
        if (text === undefined) {
            problems.push(`src/locales/${language}.json: missing "${key}"`);
        } else if (!text.trim()) {
            problems.push(`src/locales/${language}.json: empty "${key}"`);
        }
    }
    const [first, ...others] = languages.map(
        (language) => dictionaries[language][key] ?? '',
    );
    if (others.some((text) => placeholders(text) !== placeholders(first))) {
        problems.push(
            `src/locales: "${key}" has different {placeholders} in ${languages.join(' and ')}`,
        );
    }
}

const code = files(sourceDir, ['.vue', '.ts'])
    .map((file) => readFileSync(file, 'utf8'))
    .join('\n');
const dynamicPrefixes = [
    ...code.matchAll(/`([a-z_]+(?:\.[a-z_]+)*\.)\$\{/g),
].map((match) => match[1]);
for (const key of allKeys) {
    const used =
        code.includes(`'${key}'`) ||
        code.includes(`"${key}"`) ||
        dynamicPrefixes.some((prefix) => key.startsWith(prefix));
    if (!used) {
        problems.push(`src/locales: "${key}" is not used in src`);
    }
}

if (problems.length) {
    console.error(problems.join('\n'));
    console.error(`\nUI rules: ${problems.length} problem(s).`);
    process.exit(1);
}
console.log(
    'pass: UI rules (no hex colors or inline styles; translations complete and used)',
);
