/**
 * Cloudflare Worker — پل ارسال پیام به تلگرام
 * 
 * مراحل استقرار:
 * 1. وارد داشبورد Cloudflare شوید: https://dash.cloudflare.com
 * 2. به بخش Workers & Pages بروید
 * 3. روی "Create application" کلیک کنید
 * 4. گزینه "Create Worker" را انتخاب کنید
 * 5. این کد را جایگزین کد پیش‌فرض کنید
 * 6. متغیر BOT_TOKEN را با توکن ربات تلگرام خود جایگزین کنید
 * 7. روی "Save and Deploy" کلیک کنید
 * 8. آدرس Worker را در تنظیمات پلاگین (Telegram Worker URL) وارد کنید
 *
 * فرمت درخواست دریافتی:
 * POST / با body: { "chat_id": "...", "text": "..." }
 */

const BOT_TOKEN = 'YOUR_BOT_TOKEN_HERE'; // <-- توکن ربات خود را اینجا وارد کنید

export default {
  async fetch(request, env, ctx) {

    // فقط POST قبول می‌شود
    if (request.method !== 'POST') {
      return new Response(JSON.stringify({ ok: false, error: 'Method not allowed' }), {
        status: 405,
        headers: { 'Content-Type': 'application/json' },
      });
    }

    let body;
    try {
      body = await request.json();
    } catch (e) {
      return new Response(JSON.stringify({ ok: false, error: 'Invalid JSON' }), {
        status: 400,
        headers: { 'Content-Type': 'application/json' },
      });
    }

    const { chat_id, text } = body;

    if (!chat_id || !text) {
      return new Response(JSON.stringify({ ok: false, error: 'chat_id and text are required' }), {
        status: 400,
        headers: { 'Content-Type': 'application/json' },
      });
    }

    // ارسال به تلگرام
    const tgUrl = `https://api.telegram.org/bot${BOT_TOKEN}/sendMessage`;
    const tgRes = await fetch(tgUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        chat_id:    chat_id,
        text:       text,
        parse_mode: 'HTML',
      }),
    });

    const tgData = await tgRes.json();

    return new Response(JSON.stringify(tgData), {
      status: tgRes.status,
      headers: { 'Content-Type': 'application/json' },
    });
  },
};
