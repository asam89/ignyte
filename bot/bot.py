#!/usr/bin/env python3
"""
IGNYTE Deploy Bot v2
- Reads current site files before generating changes
- Command-based routing for targeted updates
- Writes to public_html/dev/ (not production)
- Tracks deployments to avoid duplicates
- Supports section-level edits without full regeneration
"""

import os, subprocess, logging, json, hashlib, glob
from datetime import datetime
from telegram import Update
from telegram.ext import Application, MessageHandler, CommandHandler, filters, ContextTypes
import anthropic

TELEGRAM_TOKEN = os.environ.get("TELEGRAM_TOKEN")
CLAUDE_API_KEY = os.environ.get("CLAUDE_API_KEY")
REPO_PATH = os.environ.get("REPO_PATH", "/home/ubuntu/ignyte")
PROD_DIR = os.path.join(REPO_PATH, "public_html")
DEV_DIR = os.path.join(REPO_PATH, "public_html", "dev")
DEPLOY_LOG = os.path.join(REPO_PATH, ".deploy_log.json")
ALLOWED_USER_IDS = os.environ.get("ALLOWED_USERS", "").split(",")  # comma-separated Telegram user IDs

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

claude = anthropic.Anthropic(api_key=CLAUDE_API_KEY)


# ─── Helpers ──────────────────────────────────────────────────────────────────

def read_site_files(directory=PROD_DIR, extensions=(".html", ".css", ".js")):
    """Read all current site files and return as a context dict."""
    files = {}
    for ext in extensions:
        for filepath in glob.glob(os.path.join(directory, f"**/*{ext}"), recursive=True):
            rel_path = os.path.relpath(filepath, directory)
            try:
                with open(filepath, "r", encoding="utf-8") as f:
                    content = f.read()
                files[rel_path] = content
            except Exception as e:
                logger.warning(f"Could not read {filepath}: {e}")
    return files


def content_hash(content):
    """Hash content to detect duplicates."""
    return hashlib.md5(content.encode()).hexdigest()


def load_deploy_log():
    """Load deployment history."""
    if os.path.exists(DEPLOY_LOG):
        with open(DEPLOY_LOG, "r") as f:
            return json.load(f)
    return []


def save_deploy_log(log):
    """Save deployment history."""
    with open(DEPLOY_LOG, "w") as f:
        json.dump(log, f, indent=2)


def log_deployment(filename, hash_val, prompt_summary):
    """Record a deployment."""
    log = load_deploy_log()
    log.append({
        "file": filename,
        "hash": hash_val,
        "prompt": prompt_summary[:100],
        "timestamp": datetime.now().isoformat()
    })
    # Keep last 50 entries
    save_deploy_log(log[-50:])


def was_already_deployed(filename, hash_val):
    """Check if identical content was already deployed."""
    log = load_deploy_log()
    return any(entry["file"] == filename and entry["hash"] == hash_val for entry in log)


def git_push(repo_path, message):
    """Push changes to GitHub."""
    try:
        os.chdir(repo_path)
        subprocess.run(["git", "add", "."], check=True, capture_output=True)
        result = subprocess.run(
            ["git", "status", "--porcelain"], capture_output=True, text=True
        )
        if not result.stdout.strip():
            return "⚠️ No changes to push."
        subprocess.run(["git", "commit", "-m", message], check=True, capture_output=True, text=True)
        subprocess.run(["git", "push", "origin", "main"], check=True, capture_output=True)
        return "✅ Pushed to GitHub → Hostinger auto-deploy triggered"
    except subprocess.CalledProcessError as e:
        return f"❌ Git error: {e.stderr if hasattr(e, 'stderr') else str(e)}"


def build_site_context(files):
    """Format current site files as context for Claude."""
    if not files:
        return "No existing site files found."
    context_parts = []
    for path, content in files.items():
        # Truncate very large files to keep within token limits
        if len(content) > 15000:
            content = content[:15000] + "\n<!-- ... truncated ... -->"
        context_parts.append(f"=== {path} ===\n{content}")
    return "\n\n".join(context_parts)


# ─── Claude Generation ────────────────────────────────────────────────────────

SYSTEM_PROMPT = """You are the IGNYTE Consulting website developer. You modify an existing static website.

RULES:
1. You receive the CURRENT site files as context. READ THEM CAREFULLY before making changes.
2. Only modify what the user asks for. Do NOT regenerate the entire site from scratch.
3. Preserve all existing structure, styling, content, and functionality unless told otherwise.
4. Brand: IGNYTE Consulting — dark theme (#0a0a0a backgrounds), orange accents (#ff6600), modern/professional.
5. Output ONLY the complete file content. No markdown fences, no explanations, no commentary.
6. If editing a specific section, output the FULL file with that section changed (not just a snippet).
7. If asked to create a NEW file (e.g., a new page), output the complete file.

IMPORTANT: Think of yourself as editing an existing codebase, not generating from scratch."""


async def generate_with_context(prompt, target_file="index.html", site_files=None):
    """Generate HTML with full site context."""
    if site_files is None:
        site_files = read_site_files()

    site_context = build_site_context(site_files)

    user_message = f"""Here are the CURRENT site files:

{site_context}

---

TARGET FILE TO MODIFY: {target_file}

USER REQUEST: {prompt}

Output the complete updated {target_file} file. Only change what was requested. Preserve everything else."""

    response = claude.messages.create(
        model="claude-sonnet-4-20250514",
        max_tokens=8192,
        system=SYSTEM_PROMPT,
        messages=[{"role": "user", "content": user_message}]
    )

    html = response.content[0].text.strip()
    # Clean markdown fences if Claude adds them despite instructions
    if html.startswith("```"):
        lines = html.split("\n", 1)
        html = lines[1] if len(lines) > 1 else html[3:]
    if html.endswith("```"):
        html = html[:-3]
    return html.strip()


# ─── Command Handlers ─────────────────────────────────────────────────────────

async def cmd_start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show help."""
    help_text = """🔥 *IGNYTE Deploy Bot v2*

*Commands:*
/edit `<prompt>` — Edit index.html (reads current site first)
/editfile `<filename>` `<prompt>` — Edit a specific file
/newfile `<filename>` `<prompt>` — Create a new file
/status — Show current site files & recent deploys
/diff — Show what's pending in /dev vs production
/deploy — Push staged /dev changes to GitHub
/preview — Show pending changes before deploy
/cancel — Cancel pending changes
/help — Show this message

*Quick edit (no command):*
Just send a message and it will edit index.html

*Examples:*
• `/edit Add a testimonials section after services`
• `/editfile styles.css Make the nav sticky`
• `/newfile about.html Create an about page`
• `Change the hero headline to "AI-Powered IT Solutions"`
"""
    await update.message.reply_text(help_text, parse_mode="Markdown")


async def cmd_help(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await cmd_start(update, context)


async def cmd_status(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show current site files and recent deployments."""
    # List production files
    prod_files = read_site_files(PROD_DIR)
    dev_files = read_site_files(DEV_DIR)

    msg = "📁 *Production files (public_html):*\n"
    for f in sorted(prod_files.keys()):
        size = len(prod_files[f])
        msg += f"  • `{f}` ({size:,} chars)\n"

    if dev_files:
        msg += "\n📁 *Staged in /dev:*\n"
        for f in sorted(dev_files.keys()):
            size = len(dev_files[f])
            msg += f"  • `{f}` ({size:,} chars)\n"
    else:
        msg += "\n📁 *Staged in /dev:* (empty)\n"

    # Recent deploys
    log = load_deploy_log()
    if log:
        msg += "\n📋 *Recent deploys:*\n"
        for entry in log[-5:]:
            msg += f"  • `{entry['file']}` — {entry['timestamp'][:16]}\n"
            msg += f"    _{entry['prompt']}_\n"

    await update.message.reply_text(msg, parse_mode="Markdown")


async def cmd_edit(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Edit index.html with site context."""
    prompt = update.message.text.replace("/edit", "", 1).strip()
    if not prompt:
        await update.message.reply_text("Usage: `/edit <what to change>`", parse_mode="Markdown")
        return
    await _generate_and_stage(update, context, prompt, "index.html")


async def cmd_editfile(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Edit a specific file."""
    parts = update.message.text.replace("/editfile", "", 1).strip().split(" ", 1)
    if len(parts) < 2:
        await update.message.reply_text("Usage: `/editfile <filename> <what to change>`", parse_mode="Markdown")
        return
    filename, prompt = parts[0], parts[1]
    await _generate_and_stage(update, context, prompt, filename)


async def cmd_newfile(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Create a new file."""
    parts = update.message.text.replace("/newfile", "", 1).strip().split(" ", 1)
    if len(parts) < 2:
        await update.message.reply_text("Usage: `/newfile <filename> <prompt>`", parse_mode="Markdown")
        return
    filename, prompt = parts[0], parts[1]
    await _generate_and_stage(update, context, prompt, filename, is_new=True)


async def _generate_and_stage(update, context, prompt, target_file, is_new=False):
    """Core: generate content with Claude using site context, stage to /dev."""
    await update.message.reply_text(f"🔄 Reading current site files...")

    site_files = read_site_files()
    file_count = len(site_files)

    if not is_new and target_file not in site_files:
        # Check if file exists but wasn't caught (e.g., different extension)
        available = ", ".join(sorted(site_files.keys()))
        await update.message.reply_text(
            f"⚠️ `{target_file}` not found in production.\n"
            f"Available files: `{available}`\n\n"
            f"Use `/newfile {target_file} <prompt>` to create it.",
            parse_mode="Markdown"
        )
        return

    await update.message.reply_text(
        f"📖 Read {file_count} file(s). Generating changes with Claude..."
    )

    try:
        generated = await generate_with_context(prompt, target_file, site_files)

        # Check for duplicate deployment
        h = content_hash(generated)
        if was_already_deployed(target_file, h):
            await update.message.reply_text(
                "⚠️ This exact content was already deployed. No changes needed."
            )
            return

        # Stage to /dev
        dev_filepath = os.path.join(DEV_DIR, target_file)
        os.makedirs(os.path.dirname(dev_filepath), exist_ok=True)
        with open(dev_filepath, "w", encoding="utf-8") as f:
            f.write(generated)

        # Store pending info for confirmation
        context.user_data["pending"] = {
            "file": target_file,
            "hash": h,
            "prompt": prompt,
            "dev_path": dev_filepath,
        }

        # Show preview
        preview = generated[:800]
        if len(generated) > 800:
            preview += "\n..."

        msg = (
            f"✅ *Staged to /dev/{target_file}*\n"
            f"📏 Size: {len(generated):,} chars\n\n"
            f"```\n{preview}\n```\n\n"
            f"→ Reply *yes* to deploy or *no* to cancel\n"
            f"→ Or use `/preview` to see more"
        )
        await update.message.reply_text(msg, parse_mode="Markdown")

    except Exception as e:
        logger.error(f"Generation error: {e}")
        await update.message.reply_text(f"❌ Error: {str(e)}")


async def cmd_deploy(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Push all staged /dev changes to GitHub."""
    pending = context.user_data.get("pending")
    if not pending:
        # Check if there are any files in dev anyway
        dev_files = read_site_files(DEV_DIR)
        if not dev_files:
            await update.message.reply_text("Nothing staged in /dev to deploy.")
            return

    await update.message.reply_text("🚀 Pushing to GitHub...")
    commit_msg = f"Bot deploy: {pending['prompt'][:60]}" if pending else "Bot deploy: manual push"
    result = git_push(REPO_PATH, commit_msg)

    if pending and "✅" in result:
        log_deployment(pending["file"], pending["hash"], pending["prompt"])
        context.user_data["pending"] = None

    await update.message.reply_text(result)


async def cmd_preview(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show full pending content."""
    pending = context.user_data.get("pending")
    if not pending:
        await update.message.reply_text("Nothing pending. Use `/edit` first.", parse_mode="Markdown")
        return

    try:
        with open(pending["dev_path"], "r") as f:
            content = f.read()

        # Telegram has 4096 char limit, send in chunks
        chunks = [content[i:i+3900] for i in range(0, len(content), 3900)]
        for i, chunk in enumerate(chunks):
            await update.message.reply_text(f"```\n{chunk}\n```", parse_mode="Markdown")
    except Exception as e:
        await update.message.reply_text(f"Error reading preview: {e}")


async def cmd_cancel(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Cancel pending changes."""
    pending = context.user_data.get("pending")
    if pending:
        # Remove staged file
        try:
            os.remove(pending["dev_path"])
        except OSError:
            pass
        context.user_data["pending"] = None
        await update.message.reply_text("🗑️ Cancelled. Staged file removed.")
    else:
        await update.message.reply_text("Nothing to cancel.")


async def cmd_diff(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show differences between /dev and production."""
    prod_files = read_site_files(PROD_DIR)
    dev_files = read_site_files(DEV_DIR)

    if not dev_files:
        await update.message.reply_text("No files staged in /dev.")
        return

    msg = "📊 *Diff: /dev vs production*\n\n"
    for filename in dev_files:
        if filename in prod_files:
            prod_size = len(prod_files[filename])
            dev_size = len(dev_files[filename])
            if prod_files[filename] == dev_files[filename]:
                msg += f"• `{filename}` — identical ✓\n"
            else:
                msg += f"• `{filename}` — *modified* (prod: {prod_size:,} → dev: {dev_size:,} chars)\n"
        else:
            msg += f"• `{filename}` — *new file* ({len(dev_files[filename]):,} chars)\n"

    await update.message.reply_text(msg, parse_mode="Markdown")


# ─── Confirmation Handler ─────────────────────────────────────────────────────

async def handle_confirm(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Handle yes/no confirmation for pending deploys."""
    text = update.message.text.lower().strip()
    pending = context.user_data.get("pending")

    if not pending:
        return  # No pending action, fall through

    if text in ("yes", "y"):
        await cmd_deploy(update, context)
    elif text in ("no", "n"):
        await cmd_cancel(update, context)


# ─── Default Message Handler ──────────────────────────────────────────────────

async def handle_message(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Default: treat plain messages as index.html edit requests."""
    # If there's a pending deploy, handle confirmation
    if context.user_data.get("pending"):
        await update.message.reply_text(
            "⏳ You have a pending deploy. Reply *yes* / *no* first, or `/cancel`",
            parse_mode="Markdown"
        )
        return

    prompt = update.message.text.strip()
    if not prompt:
        return

    # Default behavior: edit index.html
    await _generate_and_stage(update, context, prompt, "index.html")


# ─── Main ─────────────────────────────────────────────────────────────────────

def main():
    print("🔥 IGNYTE Bot v2 starting...", flush=True)

    # Ensure /dev directory exists
    os.makedirs(DEV_DIR, exist_ok=True)

    app = Application.builder().token(TELEGRAM_TOKEN).build()

    # Commands
    app.add_handler(CommandHandler("start", cmd_start))
    app.add_handler(CommandHandler("help", cmd_help))
    app.add_handler(CommandHandler("edit", cmd_edit))
    app.add_handler(CommandHandler("editfile", cmd_editfile))
    app.add_handler(CommandHandler("newfile", cmd_newfile))
    app.add_handler(CommandHandler("status", cmd_status))
    app.add_handler(CommandHandler("deploy", cmd_deploy))
    app.add_handler(CommandHandler("preview", cmd_preview))
    app.add_handler(CommandHandler("cancel", cmd_cancel))
    app.add_handler(CommandHandler("diff", cmd_diff))

    # Yes/No confirmation (must come before general message handler)
    app.add_handler(MessageHandler(filters.Regex(r"(?i)^(yes|y|no|n)$"), handle_confirm))

    # Default: plain text → edit index.html
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_message))

    app.run_polling()


if __name__ == "__main__":
    main()
