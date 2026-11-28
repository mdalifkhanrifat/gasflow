// UI guardrails from the design system (docs/project-structure.md, Frontend):
// colors come from tokens and styling from shared classes, so components
// carry no hex colors and no static inline styles. Runs with `npm run check`.
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

if (problems.length) {
    console.error(problems.join('\n'));
    console.error(`\nUI rules: ${problems.length} problem(s).`);
    process.exit(1);
}
console.log('pass: UI rules (no hex colors or inline styles in components)');
