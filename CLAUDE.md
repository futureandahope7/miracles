# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Read the following files to get an overview of this project:

- @context-miracle/project-overview.md

The following file lists tasks that we need to complete, and ones we have already worked on.

- @context-miracle/project-progress.md

## Running locally

The project lives inside an XAMPP install (`D:\xampp\htdocs\miracle`). It has no build step, package manager, linter or tests.

- Start Apache from the XAMPP Control Panel, then open http://localhost/miracle/
- Or, without Apache: `php -S localhost:8000` from the project root (requires `php` on PATH, e.g. `D:\xampp\php\php.exe`)
- Syntax-check a file: `php -l index.php`

If a database becomes necessary, XAMPP ships MySQL/MariaDB, managed through http://localhost/phpmyadmin.

## Gotchas

