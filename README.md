# Contentfly — `areanet/contentfly`

Data storage and core functions as a library: PHP 8.3+, a Symfony 7.4 kernel, Doctrine ORM 3.
Access is through the API and the console; there is no user interface.

## Installing it in a project

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:area-net-gmbh/contentfly-framework-dist.git"
        }
    ],
    "require": {
        "areanet/contentfly": "^2.0"
    }
}
```

```sh
composer require areanet/contentfly:^2.0
```

An update is `composer update areanet/contentfly` — the project never touches the framework tree.
That is the whole point of the package: until Contentfly 2 a project carried a **copy** of `lib/`,
and a new version only arrived by overwriting the tree that also held the project's own code.

Coming from Contentfly 1.x: `an_project/docs/migration.md` in the development repository walks the
nine phases, and `breaking-changes.md` is the register behind it.

## This repository is generated — do not commit here

**Every tag `v*` on the development repository rewrites this one.** Whatever is committed here by
hand disappears with the next release, silently and without a conflict.

| | |
|---|---|
| Source | `github.com/area-net-gmbh/contentfly-framework`, directory `lib/contentfly` |
| Built by | `tools/ci/paket-veroeffentlichen.sh` via `.github/workflows/paket.yml` |
| Contains | the library alone — no tests, no tooling, no backlog |

Why the split exists at all: Composer reads `composer.json` from the **root** of a repository. In
the development repository it sits in `lib/contentfly`, and the root carries the project skeleton
(`areanet/contentfly-skeleton`). Without this repository no project could obtain the package —
measured on the existing project UFP, whose `composer.json` needed an absolute path that only
existed inside one container.

**Issues, pull requests and discussion belong in the development repository**, not here.

## License

Proprietary. See `LICENSE` in the development repository.
