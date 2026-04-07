# Contributing to Media Janitor

First off, thank you for taking the time to contribute to **Media Janitor**! 🎉 

Whether it's reporting a bug, discussing ideas, or submitting a Pull Request, your help is greatly appreciated to make this project better. This document provides guidelines and workflows for contributing to the repository.

## Table of Contents
- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
  - [Reporting Bugs](#reporting-bugs)
  - [Suggesting Enhancements](#suggesting-enhancements)
  - [Submitting Pull Requests](#submitting-pull-requests)
- [Local Development Setup](#local-development-setup)
- [Coding Guidelines](#coding-guidelines)

---

## Code of Conduct
By participating in this project, you are expected to uphold our [Code of Conduct](https://github.com/wisnuub/media-janitor/blob/main/CODE_OF_CONDUCT.md). Please report any unacceptable behavior to the project maintainers.

## How Can I Contribute?

### Reporting Bugs
If you find a bug, please check the [Issue Tracker](https://github.com/wisnuub/media-janitor/issues) to see if it has already been reported. If not, please open a new issue and include:
- A clear and descriptive title.
- Steps to reproduce the bug.
- Your environment details (e.g., PHP version, WordPress version, Node version, browser).
- Expected behavior vs. actual behavior.
- Screenshots or error logs if applicable.

### Suggesting Enhancements
We are always open to new ideas! Before submitting an enhancement request, please search the [Issue Tracker](https://github.com/wisnuub/media-janitor/issues) to ensure it hasn't been discussed yet.
When suggesting a feature, provide:
- A detailed description of the proposed functionality.
- Why this feature would be useful for Media Janitor users.
- Potential implementation ideas if you have them.

### Submitting Pull Requests
1. **Fork** the repository and create your branch from `main`.
2. Name your branch descriptively (e.g., `feat/auto-cleanup`, `fix/image-deletion-error`).
3. If you've added code that should be tested, add tests.
4. Update the `README.md` if your changes affect how the project is installed or configured.
5. Ensure your code lints and passes all tests.
6. Open a Pull Request referencing any related issues (e.g., `Closes #12`).

## Local Development Setup

To set up the project locally for development:

1. Clone your fork:
   ```bash
   git clone https://github.com/YOUR_USERNAME/media-janitor.git
   cd media-janitor
   ```
2. Install dependencies (adjust according to the project's current stack):
   ```bash
   # If using Composer (PHP)
   composer install

   # If using NPM (JavaScript/Node)
   npm install
   ```
3. Create a new branch for your work:
   ```bash
   git checkout -b your-feature-branch
   ```
4. Make your changes, test them locally, and commit.

## Coding Guidelines

To ensure code consistency across the project, please adhere to the following conventions:
- **PHP**: Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards.
- **JavaScript/TypeScript**: Use standard ES6+ syntax. If an ESLint or Prettier configuration exists in the project, ensure your code passes the linting checks.
- **Commit Messages**: Write clear, concise commit messages. Use conventional commits if possible (e.g., `feat: added bulk delete button`, `fix: resolved permission issue`).
- **Comments**: Comment your code thoughtfully, especially for complex logic or database queries.

---

Thank you for helping improve **Media Janitor**! 🚀
