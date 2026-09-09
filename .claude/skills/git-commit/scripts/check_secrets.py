#!/usr/bin/env python3
"""Scan staged git changes for likely secrets before a commit.

Checks two things:
  1. Filenames being staged that are conventionally sensitive (.env, private
     keys, credential dumps...) — flagged regardless of content.
  2. Added lines (the +side of the staged diff) matching common secret
     shapes: cloud provider keys, generic api_key/token/password assignments,
     private key headers, JWTs.

This is a heuristic net, not a guarantee — it exists to catch the common,
careless case (a real key pasted into a config or a .env file staged by
accident), not to replace judgment. A clean run does not certify a commit as
secret-free; a flagged run always deserves a human look, never a silent
bypass.

Exit code 0 = nothing suspicious found. Exit code 1 = findings to review.
"""

from __future__ import annotations

import re
import subprocess
import sys

# Windows consoles often default to a legacy codepage (cp1252) that cannot
# encode the emoji used below. Force UTF-8 on stdout/stderr so this runs the
# same in Git Bash, PowerShell, and everywhere else.
for _stream in (sys.stdout, sys.stderr):
    try:
        _stream.reconfigure(encoding="utf-8", errors="replace")
    except AttributeError:
        pass

SENSITIVE_FILENAME_PATTERNS = [
    re.compile(r"(^|/)\.env(\.|$)"),
    re.compile(r"(^|/)\.env\..+$"),
    re.compile(r"(^|/)id_rsa$"),
    re.compile(r"(^|/)id_ed25519$"),
    re.compile(r"\.pem$"),
    re.compile(r"\.pfx$"),
    re.compile(r"\.p12$"),
    re.compile(r"(^|/)credentials(\.json)?$"),
    re.compile(r"(^|/)\.npmrc$"),
    re.compile(r"(^|/)\.netrc$"),
    re.compile(r"(^|/)\.pgpass$"),
    re.compile(r"(^|/)secrets?\.ya?ml$"),
    re.compile(r"(^|/)service[-_]account.*\.json$"),
]

# (label, regex) — matched against individual added lines.
SECRET_CONTENT_PATTERNS = [
    ("Clé AWS Access Key ID", re.compile(r"\bAKIA[0-9A-Z]{16}\b")),
    ("Clé API Google", re.compile(r"\bAIza[0-9A-Za-z_\-]{35}\b")),
    ("Token Slack", re.compile(r"\bxox[baprs]-[0-9A-Za-z-]{10,}\b")),
    ("Token GitHub", re.compile(r"\bgh[pousr]_[0-9A-Za-z]{36,}\b")),
    ("Clé Stripe live", re.compile(r"\bsk_live_[0-9A-Za-z]{16,}\b")),
    ("En-tête de clé privée", re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----")),
    ("JWT en clair", re.compile(r"\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b")),
    (
        "Affectation générique de secret",
        re.compile(
            r"(?i)\b(api[_-]?key|secret|token|password|passwd|pwd)\b\s*[:=]\s*"
            r"['\"][A-Za-z0-9_\-/+=]{12,}['\"]"
        ),
    ),
]

# Placeholders that legitimately look like secrets but aren't — skip these
# rather than force every example DSN in documentation to be flagged.
BENIGN_VALUE_HINTS = re.compile(
    r"(?i)\b(changeme|change_me|example|placeholder|xxxx|your[_-]?"
    r"(api[_-]?key|token|secret)|dummy|fake|test[_-]?key|sample)\b"
)


def run(cmd: list[str]) -> str:
    # git diff output is UTF-8 regardless of the console's locale (cp1252 on a
    # default Windows setup), so decode explicitly rather than let subprocess
    # guess and crash on the first accented character or emoji.
    result = subprocess.run(
        cmd,
        capture_output=True,
        encoding="utf-8",
        errors="replace",
        check=False,
    )
    return result.stdout or ""


def staged_files() -> list[str]:
    out = run(["git", "diff", "--cached", "--name-only", "--diff-filter=ACM"])
    return [line.strip() for line in out.splitlines() if line.strip()]


def check_filenames(files: list[str]) -> list[tuple[str, str]]:
    findings = []
    for f in files:
        for pattern in SENSITIVE_FILENAME_PATTERNS:
            if pattern.search(f.replace("\\", "/")):
                findings.append((f, "Nom de fichier sensible par convention"))
                break
    return findings


def check_content(files: list[str]) -> list[tuple[str, str, str]]:
    findings = []
    for f in files:
        diff = run(["git", "diff", "--cached", "-U0", "--", f])
        for line in diff.splitlines():
            if not line.startswith("+") or line.startswith("+++"):
                continue
            added = line[1:]
            if BENIGN_VALUE_HINTS.search(added):
                continue
            for label, pattern in SECRET_CONTENT_PATTERNS:
                if pattern.search(added):
                    snippet = added.strip()
                    if len(snippet) > 100:
                        snippet = snippet[:100] + "…"
                    findings.append((f, label, snippet))
    return findings


def main() -> int:
    files = staged_files()
    if not files:
        print("Rien à vérifier : aucun fichier stagé.")
        return 0

    name_findings = check_filenames(files)
    content_findings = check_content(files)

    if not name_findings and not content_findings:
        print(f"✅ Rien de suspect sur {len(files)} fichier(s) stagé(s).")
        return 0

    print("🚨 Éléments à vérifier avant de committer :\n")

    for f, reason in name_findings:
        print(f"  - {f}\n    {reason}")

    for f, label, snippet in content_findings:
        print(f"  - {f}\n    {label} : {snippet}")

    print(
        "\nCeci est une détection heuristique, pas une preuve — regarde chaque "
        "ligne toi-même avant de décider. Si c'est un faux positif (une "
        "valeur d'exemple, un placeholder), dis-le explicitement plutôt que "
        "de relancer avec --no-verify ou d'ignorer l'avertissement en silence."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
