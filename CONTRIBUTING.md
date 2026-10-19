# Contributing to UCF Plugin Directory

Thank you for your interest in contributing to this project!  If you are a developer for UCF and want to contribute to this plugin, we'd love to hear from you.

This document outlines the best ways to submit new ideas or inform us of bugs.  Please take a moment to review these guidelines before submitting new issues or pull requests in order to make the contribution process easy and effective for everyone involved.

> **This plugin is under development.**  It is a proof of concept awaiting approval, and its scope is set by [PLAN.md](PLAN.md).  During this stage, please [ask first](#asking-questionsgetting-help) before starting work on any pull request, even a small one.

## Quick links
- [Contributing to UCF Plugin Directory](#contributing-to-ucf-plugin-directory)
  - [Quick links](#quick-links)
  - [Using the issue tracker](#using-the-issue-tracker)
  - [Reporting security issues](#reporting-security-issues)
  - [Bug reports](#bug-reports)
    - [Before submitting a bug report](#before-submitting-a-bug-report)
    - [Submit a bug report](#submit-a-bug-report)
  - [Feature requests](#feature-requests)
  - [Pull requests](#pull-requests)
    - [How to submit a pull request](#how-to-submit-a-pull-request)
  - [Asking questions/getting help](#asking-questionsgetting-help)
  - [Code standards and style guides](#code-standards-and-style-guides)
    - [PHP](#php)
    - [Tests](#tests)
    - [HTML](#html)

-----

## Using the issue tracker

The [issue tracker](https://github.com/UCF/UCF-Plugin-Directory/issues) in Github is the preferred channel for [bug reports](#bug-reports), [feature requests](#feature-requests) and [submitting pull requests](#pull-requests).

Please do not use the issue tracker for personal support requests.  The [#help-plugins Teams channel](https://teams.microsoft.com/l/channel/19%3a5325780226474b76b53d50029eae9e22%40thread.skype/help-plugins?groupId=5e3e72e2-3599-47b3-bcfc-bb3d5b579f12&tenantId=bb932f15-ef38-42ba-91fc-f3c59d5dd1f1) is the best place to get help with your project.  See the section on [getting help](#asking-questionsgetting-help) for more information.


## Reporting security issues

This plugin downloads and installs code on WordPress sites, so security problems need to be handled privately.  **Please do not report security issues in the public issue tracker.**  Instead, email UCF Web Communications at [webcom@ucf.edu](mailto:webcom@ucf.edu) with a description of the problem and steps to reproduce it.

Examples include a way to install a package from outside the UCF GitHub organization, a way to bypass the download allowlist, or a way to expose the GitHub token.  See the "Security model" section of the [README](README.md#security-model) for what the plugin is designed to guarantee.


## Bug reports

A bug is a demonstrable problem that is caused by the code in the repository. Concise and thorough bug reports will help us fix reported problems more quickly and effectively.

### Before submitting a bug report
Before you submit a new bug report, please follow these steps:

1. **Use the GitHub issue search** &mdash; check if the issue has already been reported.  Feel free to comment in the existing issue if it is still open and you have new information to share.

2. **Check if the issue has been fixed** &mdash; if you're not running the latest version of the plugin, please check your code against the repo's `main` branch first (`main` will always contain the latest, stable project code). If you are running the latest version, make sure the problem isn't already resolved in an upcoming [milestone](https://github.com/UCF/UCF-Plugin-Directory/milestones).

### Submit a bug report
If you've followed the steps above and have a valid bug report to submit, you can submit it by [creating a new issue in Github](https://github.com/UCF/UCF-Plugin-Directory/issues/new?template=bug_report.md).

Add a descriptive, understandable title and details about the bug in the description field, following the template provided. Please try to be as detailed as possible in your report. What steps will reproduce the issue? What browser(s) and OS experience the problem? Do other browsers show the bug differently? What would you expect to be the outcome? All of the information you provide will help us quickly evaluate and fix the issue.

If you have a live example of the bug available somewhere public, please include a link in the bug report.  If you're not comfortable including the URL in the Github issue (e.g. it points to a development environment), you can make a note of it in the report (e.g. "see Teams for example URL"), then share the URL in the [#help-plugins Teams channel](https://teams.microsoft.com/l/channel/19%3a5325780226474b76b53d50029eae9e22%40thread.skype/help-plugins?groupId=5e3e72e2-3599-47b3-bcfc-bb3d5b579f12&tenantId=bb932f15-ef38-42ba-91fc-f3c59d5dd1f1).


## Feature requests

We welcome new feature requests from developers across campus.  Before submitting a new request, think carefully about if the proposed feature aligns with the [goals of the project](PLAN.md#2-goals-and-non-goals) and with [UCF's brand](https://www.ucf.edu/brand/). We strongly encourage the discussion of new feature ideas in the [UCFTeam-CAMPUS-Wordpress Team](https://teams.microsoft.com/l/team/19%3acf4fa1fd7b1042949ecd1acf5413aa22%40thread.skype/conversations?groupId=5e3e72e2-3599-47b3-bcfc-bb3d5b579f12&tenantId=bb932f15-ef38-42ba-91fc-f3c59d5dd1f1).

Please provide as much detail and context as possible to justify the inclusion of your idea in the plugin. We reserve the right to deny feature requests when they don't align with the project's goals, or if said feature is already accomplishable with existing utilities/components.

You can submit a new feature request by [creating a new issue in Github](https://github.com/UCF/UCF-Plugin-Directory/issues/new?template=feature_request.md) and filling out the provided template.


## Pull requests

[**Please ask first**](#asking-questionsgetting-help) before embarking on any _significant_ pull request (e.g. implementing features, refactoring code); otherwise you risk spending a lot of time working on something that the plugin's maintainers might not want to merge into the project. Pull requests should be related to existing issues that have been acknowledged by UCF Web Communications.

All pull requests should remain focused in scope and avoid containing unrelated commits.

Your pull request will be reviewed by at least one maintainer of the plugin.  While your code should be complete enough to be understood by the person reviewing it, we don't want to spend an extensive amount of time reviewing code--try to keep your code sample brief enough to be reviewed within one hour.

Please adhere to the [coding guidelines](#code-standards-and-style-guides) used throughout the project (indentation, accurate comments, etc.)  Code that does not adhere to these standards will not be merged into the project.

### How to submit a pull request

Adhering to the following process is the best way to submit a pull request:

1. [Fork](https://help.github.com/articles/fork-a-repo/) the project.
2. Clone your fork, and configure the remotes:

   ```bash
   # Clone your fork of the repo into the current directory
   git clone https://github.com/<your-username>/UCF-Plugin-Directory.git

   # Navigate to the newly cloned directory
   cd UCF-Plugin-Directory

   # Assign the original repo to a remote called "upstream"
   git remote add upstream https://github.com/UCF/UCF-Plugin-Directory.git
   ```

3. If you cloned a while ago, get the latest changes from upstream:

   ```bash
   git checkout main
   git pull upstream main
   ```

4. Create a new topic branch to contain your feature, change, or fix.

   ```bash
   git checkout -b <topic-branch-name>
   ```

    New branches **must** be branched off of the most recent existing `rc-*` branch (typically there will only be one open at a time), or off of `main` directly if no `rc-*` branch exists.

    **Never create _any_ new branch from the `develop` branch**--`develop` exists solely for project maintainers' usage and is considered a "dirty" branch. **Branches created from `develop` will not be merged into the project.**

5. Commit your changes in logical chunks. Please provide [helpful, readable commit messages](https://chris.beams.io/posts/git-commit/) (avoid nondescriptive messages such as "bugfix" or "minor change").

    Before you push, make sure the test suite and the linter both pass:

   ```bash
   composer install
   composer test
   composer lint
   ```

    Changes to the catalog, version resolution, matching, or download handling should include new or updated tests in `tests/unit/`.  If your change affects installs or updates, also describe in your pull request how you checked it on a real WordPress site (single site or multisite, and whether you used the dashboard or WP-CLI).

    Documentation lives in `README.md`, which is edited by hand.  If your change adds a filter, constant, or setting, or changes how the plugin talks to the package repository or GitHub, update the README in the same pull request.

6. Locally merge the upstream `rc-*` or `main` branch (whichever you branched off of initially) into your topic branch:

   ```bash
   git fetch upstream
   git merge --no-ff upstream/main
   ```

7. Push your topic branch up to your fork:

   ```bash
   git push origin <topic-branch-name>
   ```

8. [Open a Pull Request](https://help.github.com/articles/about-pull-requests/) against the `rc-*` or `main` branch (whichever you initially branched off of.) Pull request titles and descriptions should be as descriptive and clear as possible.

-----

## Asking questions/getting help

In general, we keep conversations about distributed UCF WordPress projects in the [UCFTeam-CAMPUS-Wordpress Team](https://teams.microsoft.com/l/team/19%3acf4fa1fd7b1042949ecd1acf5413aa22%40thread.skype/conversations?groupId=5e3e72e2-3599-47b3-bcfc-bb3d5b579f12&tenantId=bb932f15-ef38-42ba-91fc-f3c59d5dd1f1).  If you're not already a member of this Team, you'll be prompted to request access to join.

Whenever you post a question in this Team, please include your **job title**, information about the **college or department** you work for, as well as the **site(s) you need help with** to help us help you more effectively.

If you need help with using this plugin on your site, please check out the [README](README.md) first.  If you can't find the answer to your question there, drop us a line in the [#help-plugins channel](https://teams.microsoft.com/l/channel/19%3a5325780226474b76b53d50029eae9e22%40thread.skype/help-plugins?groupId=5e3e72e2-3599-47b3-bcfc-bb3d5b579f12&tenantId=bb932f15-ef38-42ba-91fc-f3c59d5dd1f1).  You don't need to @mention anyone specific, but **please note that you're using the UCF Plugin Directory when posting your question** and include a link to your site if it's relevant to your question.

-----

## Code standards and style guides

Whenever you add or modify code in this repo, please follow the code style guides noted below, per language:

### PHP

[Adhere to the WordPress PHP Coding Standards](https://make.wordpress.org/core/handbook/best-practices/coding-standards/php/) for new or modified code.  The repo's `phpcs.xml.dist` encodes these rules, along with a PHP 7.4 compatibility check; `composer lint` must pass with no errors or warnings.

- Prefix every class, function, option, transient, and hook with `UCF_Plugin_Directory` / `ucf_plugin_directory_`.
- Escape all output and sanitize all input.  Treat package metadata (names, descriptions, URLs) as untrusted text, never as HTML.
- Never make HTTP requests on front-end page loads.  Fetches belong in cron, WP-CLI, or an explicit admin action.

### Tests

Unit tests use PHPUnit and run without a database (`composer test`); WordPress functions are stubbed in `tests/class-test-wp.php`.  Fixtures in `tests/fixtures/` should be real responses captured from the package repository wherever possible.

### HTML

[Adhere to the mdo Code Guide.](http://codeguide.co/#html)

- Use tags and elements appropriate for an HTML5 doctype (e.g., self-closing tags).
- Use [WAI-ARIA](https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA) attributes when appropriate to promote accessibility.
- Prefer core WordPress admin markup and classes (`wrap`, `form-table`, `notice`, `button`) over custom styles, so admin screens match the rest of the dashboard.
