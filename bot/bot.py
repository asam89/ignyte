#!/usr/bin/env python3
import os, subprocess, logging
from telegram import Update
from telegram.ext import Application, MessageHandler, CommandHandler, filters, ContextTypes
import anthropic

TELEGRAM_TOKEN = os.environ.get("TELEGRAM_TOKEN")
CLAUDE_API_KEY = os.environ.get("CLAUDE_API_KEY")
REPO_PATH = os.environ.get("REPO_PATH", "/home/ubuntu/ignyte")

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)
claude = anthropic.Anthropic(api_key=CLAUDE_API_KEY)

def git_push(repo_path, message):
    try:
        os.chdir(repo_path)
        subprocess.run(["git", "add", "."], check=True, capture_output=True)
        subprocess.run(["git", "commit", "-m", message], check=True, capture_output=True, text=True)
        subprocess.run(["git", "push", "origin", "master"], check=True, capture_output=True)
        return "Pushed to GitHub"
    except subprocess.CalledProcessError as e:
        return "Git error: " + str(e)

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text("IGNYTE Deploy Bot - Send me a prompt")

async def handle_confirm(update: Update, context: ContextTypes.DEFAULT_TYPE):
    text = update.message.text.lower().strip()
    html = context.user_data.get("pending_html")
    if not html:
        return
    if text in ("yes", "y"):
        filepath = os.path.join(REPO_PATH, "public_html", "index.html")
        os.makedirs(os.path.dirname(filepath), exist_ok=True)
        with open(filepath, "w") as f:
            f.write(html)
        result = git_push(REPO_PATH, "Deploy via Telegram bot")
        await update.message.reply_text(result)
        context.user_data["pending_html"] = None
    elif text in ("no", "n"):
        context.user_data["pending_html"] = None
        await update.message.reply_text("Cancelled.")

async def handle_message(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if context.user_data.get("pending_html"):
        await update.message.reply_text("Pending deploy. Reply yes or no first.")
        return
    prompt = update.message.text
    await update.message.reply_text("Generating with Claude...")
    try:
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
        preview = html[:500]
        if len(html) > 500:
            preview = preview + "..."
        msg = "Preview:\n" + preview + "\n\nReply yes to deploy or no to cancel."
        await update.message.reply_text(msg)
    except Exception as e:
        await update.message.reply_text("Error: " + str(e))

def main():
    print("IGNYTE Bot starting...", flush=True)
    app = Application.builder().token(TELEGRAM_TOKEN).build()
    app.add_handler(CommandHandler("start", start))
    app.add_handler(MessageHandler(filters.Regex(r"(?i)^(yes|y|no|n)$"), handle_confirm))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_message))
    app.run_polling()

if __name__ == "__main__":
    main()
