#!/usr/bin/env python3
import os, subprocess, shutil, logging
from telegram import Update
from telegram.ext import Application, MessageHandler, CommandHandler, filters, ContextTypes
import anthropic

TELEGRAM_TOKEN = os.environ.get("TELEGRAM_TOKEN")
CLAUDE_API_KEY = os.environ.get("CLAUDE_API_KEY")
REPO_PATH = os.environ.get("REPO_PATH", "/home/ubuntu/ignyte")

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)
claude = anthropic.Anthropic(api_key=CLAUDE_API_KEY)

DEV_DIR = os.path.join(REPO_PATH, "dev")
PROD_DIR = os.path.join(REPO_PATH, "public_html")

def git_push(repo_path, message):
    try:
        os.chdir(repo_path)
        subprocess.run(["git", "add", "."], check=True, capture_output=True)
        subprocess.run(["git", "commit", "-m", message], check=True, capture_output=True, text=True)
        subprocess.run(["git", "push", "origin", "main"], check=True, capture_output=True)
        return "Pushed to GitHub"
    except subprocess.CalledProcessError as e:
        return "Git error: " + str(e)

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(
        "IGNYTE Deploy Bot\n\n"
        "Send a prompt - I generate HTML to dev/\n"
        "/promote - copy dev/ to public_html/ (go live)\n"
        "/status - last 5 commits\n"
        "/diff - show what changed in dev/"
    )

async def status(update: Update, context: ContextTypes.DEFAULT_TYPE):
    try:
        os.chdir(REPO_PATH)
        result = subprocess.run(["git", "log", "--oneline", "-5"], capture_output=True, text=True)
        await update.message.reply_text("Last 5 commits:\n\n" + result.stdout)
    except Exception as e:
        await update.message.reply_text("Error: " + str(e))

async def diff(update: Update, context: ContextTypes.DEFAULT_TYPE):
    try:
        dev_index = os.path.join(DEV_DIR, "index.html")
        prod_index = os.path.join(PROD_DIR, "index.html")
        if not os.path.exists(dev_index):
            await update.message.reply_text("No dev/index.html found.")
            return
        if not os.path.exists(prod_index):
            await update.message.reply_text("No prod index.html yet. Dev is ready to promote.")
            return
        result = subprocess.run(["diff", "--brief", DEV_DIR, PROD_DIR], capture_output=True, text=True)
        if result.stdout:
            await update.message.reply_text("Differences:\n" + result.stdout)
        else:
            await update.message.reply_text("Dev and prod are identical.")
    except Exception as e:
        await update.message.reply_text("Error: " + str(e))

async def promote(update: Update, context: ContextTypes.DEFAULT_TYPE):
    try:
        dev_index = os.path.join(DEV_DIR, "index.html")
        if not os.path.exists(dev_index):
            await update.message.reply_text("Nothing in dev/ to promote.")
            return
        os.makedirs(PROD_DIR, exist_ok=True)
        for item in os.listdir(DEV_DIR):
            src = os.path.join(DEV_DIR, item)
            dst = os.path.join(PROD_DIR, item)
            if os.path.isfile(src):
                shutil.copy2(src, dst)
        result = git_push(REPO_PATH, "Promote dev to production")
        await update.message.reply_text("Promoted dev/ to public_html/\n" + result)
    except Exception as e:
        await update.message.reply_text("Error: " + str(e))

async def handle_confirm(update: Update, context: ContextTypes.DEFAULT_TYPE):
    text = update.message.text.lower().strip()
    html = context.user_data.get("pending_html")
    if not html:
        return
    if text in ("yes", "y"):
        filename = context.user_data.get("pending_filename", "index.html")
        filepath = os.path.join(DEV_DIR, filename)
        os.makedirs(DEV_DIR, exist_ok=True)
        with open(filepath, "w") as f:
            f.write(html)
        result = git_push(REPO_PATH, "Dev deploy: " + filename)
        await update.message.reply_text(
            result + "\n\n"
            "Written to dev/" + filename + "\n"
            "Use /promote to push to production."
        )
        context.user_data["pending_html"] = None
        context.user_data["pending_filename"] = None
    elif text in ("no", "n"):
        context.user_data["pending_html"] = None
        context.user_data["pending_filename"] = None
        await update.message.reply_text("Cancelled.")

async def handle_message(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if context.user_data.get("pending_html"):
        await update.message.reply_text("Pending deploy. Reply yes or no first.")
        return
    prompt = update.message.text
    await update.message.reply_text("Generating with Claude...")
    try:
        filename = "index.html"
        if "filename:" in prompt.lower():
            parts = prompt.split("filename:")
            filename = parts[1].strip().split()[0]
            prompt = parts[0].strip()

        response = claude.messages.create(
            model="claude-sonnet-4-20250514",
            max_tokens=4096,
            messages=[{"role": "user", "content": "Generate a complete HTML file. Output ONLY raw HTML, no markdown backticks. Modern CSS, responsive. Brand: IGNYTE Consulting, dark theme with orange accents. Request: " + prompt}]
        )
        html = response.content[0].text.strip()
        if html.startswith("```"):
            lines = html.split("\n", 1)
            html = lines[1] if len(lines) > 1 else html[3:]
        if html.endswith("```"):
            html = html[:-3]
        html = html.strip()
        context.user_data["pending_html"] = html
        context.user_data["pending_filename"] = filename
        preview = html[:500]
        if len(html) > 500:
            preview = preview + "..."
        msg = "File: " + filename + "\nPreview:\n\n" + preview + "\n\nReply yes to deploy to dev/ or no to cancel."
        await update.message.reply_text(msg)
    except Exception as e:
        await update.message.reply_text("Error: " + str(e))

def main():
    print("IGNYTE Bot starting...", flush=True)
    app = Application.builder().token(TELEGRAM_TOKEN).build()
    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("status", status))
    app.add_handler(CommandHandler("diff", diff))
    app.add_handler(CommandHandler("promote", promote))
    app.add_handler(MessageHandler(filters.Regex(r"(?i)^(yes|y|no|n)$"), handle_confirm))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_message))
    app.run_polling()

if __name__ == "__main__":
    main()
