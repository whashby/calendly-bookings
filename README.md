# Calendly Bookings Plugin

![GitHub release (latest by date)](https://img.shields.io/github/v/release/whashby/calendly-bookings?label=Latest%20Release&style=for-the-badge)
![GitHub Workflow Status](https://img.shields.io/github/actions/workflow/status/whashby/calendly-bookings/release.yml?branch=main&label=Release%20Pipeline&style=for-the-badge)

## Overview

Calendly Bookings is a WordPress plugin designed to integrate Calendly scheduling directly into your WordPress site.  
It provides a seamless booking experience for your users while giving administrators full control over versioning, updates, and deployment.

## Features

- Embed Calendly booking forms into posts, pages, or widgets.
- Automatic version bumping and tagging via GitHub Actions.
- CI/CD pipeline with semantic versioning and release asset packaging.
- Verified release assets with consistent folder structure (`calendly-bookings/`).
- Ready for WordPress auto‑updates using GitHub release assets.

## Release Pipeline

This repository includes a GitHub Actions workflow (`release.yml`) that:

1. **Bumps the version** based on commit messages (`feat`, `fix`, or `BREAKING CHANGE`).
2. **Updates source files** (`calendly-bookings.php`, `constants.php`, `readme.txt`, `version.txt`).
3. **Builds the plugin zip** with a stable root folder.
4. **Publishes a GitHub Release** with the packaged asset.
5. **Verifies the release asset** to ensure version alignment and folder structure.

## Installation

1. Download the latest release from the [Releases page](https://github.com/whashby/calendly-bookings/releases).
2. Upload `calendly-bookings.zip` to your WordPress site via **Plugins → Add New → Upload Plugin**.
3. Activate the plugin and configure your Calendly integration.

## Development

- Clone the repository.
- Make changes in the plugin source files.
- Commit with conventional commit messages (`feat:`, `fix:`, `BREAKING CHANGE:`).
- Push to `main` or `master` to trigger the release pipeline.

## License

This project is licensed under the MIT License.
