'use client';

import React, { createContext, useContext, useEffect, useState } from 'react';
import { layout } from './state';

export type Locale = 'en' | 'ar';

/* -------------------------------------------------------------------------- */
/* Dictionaries                                                                */
/* -------------------------------------------------------------------------- */

const en = {
  nav: {
    links: { manifesto: 'Manifesto', capabilities: 'Capabilities', flow: 'Flow' },
    login: 'Login',
    start: 'Start free',
  },
  hero: {
    badge: 'Shopify · Salla · Amazon · Noon · Zid · WooCommerce',
    title1: 'Command every store',
    title2: 'from one universe.',
    subtitle:
      'Orders, inventory and products from every channel — unified, synchronised and alive in a single command center.',
    cta1: 'Start free trial',
    cta2: 'See how it flows',
    scroll: 'Scroll',
  },
  manifesto: {
    eyebrow: 'The problem',
    text:
      'Selling everywhere shouldn’t mean managing everywhere. Every platform speaks a different language, every dashboard tells half the story. HubbyGlobal collapses that chaos into a single source of truth — where one move ripples across every store, instantly.',
  },
  showcase: {
    beats: [
      {
        eyebrow: 'Connect',
        title: 'Every storefront, one login.',
        body: 'Link Shopify, Salla, Zid and WooCommerce through a secure 30-second OAuth flow — no plugins, no code, no copy-paste.',
      },
      {
        eyebrow: 'Orders',
        title: 'Every order in a single stream.',
        body: 'Process, track and fulfil orders from all channels in one fluid list. Stop tab-hopping between dashboards that never agree.',
      },
      {
        eyebrow: 'Sync',
        title: 'Your brand, perfectly synced.',
        body: 'Adjust a product or a price once and watch it ripple across every store the instant it changes. One source of truth.',
      },
      {
        eyebrow: 'Revenue',
        title: 'Turn reach into revenue.',
        body: 'Aggregate sales across every platform and see the whole business in one bird’s-eye view — then scale what works.',
      },
    ],
  },
  capabilities: {
    eyebrow: 'Capabilities',
    heading: 'Six powers, one surface.',
    cards: [
      {
        title: 'Unified Order Hub',
        desc: 'Process, track and fulfil orders from every sales channel in one fluid stream. No more tab-hopping.',
      },
      {
        title: 'Real-time Stock Sync',
        desc: 'Adjust stock once and watch it ripple across every storefront the instant it changes.',
      },
      {
        title: 'Smart Analytics',
        desc: 'Aggregated revenue, trends and performance across all stores — one bird’s-eye view of the whole business.',
      },
      {
        title: 'Multi-Store Connect',
        desc: 'Link unlimited Shopify, Salla, Zid and WooCommerce stores with a 30-second OAuth wizard.',
      },
      {
        title: 'Role-based Access',
        desc: 'Invite your team and decide precisely who touches orders, products or billing.',
      },
      {
        title: 'Mobile Control',
        desc: 'Run the entire operation from your pocket with a high-parity native companion app.',
      },
    ],
  },
  flow: {
    eyebrow: 'The flow',
    heading: 'Live in three moves.',
    steps: [
      {
        title: 'Connect your stores',
        desc: 'Authorise Shopify, Salla, Zid or WooCommerce through a secure OAuth flow in seconds.',
      },
      {
        title: 'Set your master store',
        desc: 'Pick the source of truth that drives global inventory — the heartbeat of your network.',
      },
      {
        title: 'Scale without the overhead',
        desc: 'Manage everything from one command center and let the manual busywork disappear.',
      },
    ],
  },
  stats: [
    'Merchants scaling with us',
    'Platforms unified',
    'Sync accuracy',
    'To connect a store',
  ],
  cta: {
    eyebrow: 'Ready when you are',
    heading: 'Take control of every store.',
    body: 'Join the merchants running their entire multi-store operation from one calm, synchronised command center. 14-day free trial, no card required.',
    cta1: 'Get started now',
    cta2: 'Talk to sales',
  },
  footer: {
    tagline: 'Making multi-store e-commerce simple, synchronised and scalable.',
    columns: [
      { title: 'Platform', links: ['Capabilities', 'Integrations', 'Mobile App', 'Pricing'] },
      { title: 'Company', links: ['About', 'Blog', 'Careers', 'Contact'] },
      { title: 'Legal', links: ['Privacy Policy', 'Terms of Service', 'Cookie Policy'] },
    ],
    copyright: '© 2026 HubbyGlobal. All rights reserved.',
    social: ['Twitter', 'LinkedIn', 'GitHub'],
  },
  auth: {
    login: {
      eyebrow: 'Welcome back',
      heading: 'Command every store from one universe.',
      subheading:
        'Sign in to pick up exactly where you left off — orders, inventory and products, all in sync.',
      highlights: [
        'Unified orders across every channel',
        'Real-time stock synchronisation',
        'One dashboard for all your stores',
      ],
      title: 'Sign in',
      subtitle: 'Enter your details to access your command center.',
      email: 'Email Address',
      password: 'Password',
      forgot: 'Forgot password?',
      submit: 'Sign in',
      noAccount: 'Don’t have an account?',
      signup: 'Sign up for free',
      invalid: 'Invalid credentials',
    },
    register: {
      eyebrow: 'Get started free',
      heading: 'Bring all your stores under one roof.',
      subheading:
        'Create your account in seconds and connect Shopify, Salla, Zid and WooCommerce to a single synchronised hub.',
      highlights: [
        '14-day free trial, no card required',
        'Connect a store in 30 seconds',
        'Invite your team with role-based access',
      ],
      title: 'Create your account',
      subtitle: 'Join the merchants scaling with HubbyGlobal.',
      name: 'Full Name',
      namePlaceholder: 'John Doe',
      org: 'Organization Name',
      orgPlaceholder: 'My Business',
      email: 'Email Address',
      password: 'Password',
      confirm: 'Confirm Password',
      submit: 'Create account',
      haveAccount: 'Already have an account?',
      signin: 'Sign in',
      termsPre: 'By signing up, you agree to our',
      terms: 'Terms of Service',
      and: 'and',
      privacy: 'Privacy Policy',
      generalError: 'Something went wrong. Please try again.',
    },
    passwords: {
      forgotTitle: 'Reset your password',
      forgotSubtitle: 'Enter your email and we’ll send you a link to get back in.',
      email: 'Email Address',
      sendButton: 'Send reset link',
      sent: 'If an account exists for that email, a reset link is on its way. Check your inbox.',
      backToLogin: 'Back to sign in',
      resetTitle: 'Choose a new password',
      resetSubtitle: 'Pick a strong password you don’t use anywhere else.',
      newPassword: 'New Password',
      confirmPassword: 'Confirm Password',
      resetButton: 'Reset password',
      success: 'Your password has been reset. Redirecting you to sign in…',
      missingToken: 'This reset link is invalid or has expired. Please request a new one.',
      mismatch: 'Passwords do not match.',
      generalError: 'Something went wrong. Please try again.',
    },
  },
  langName: 'العربية',
};

type Dict = typeof en;

const ar: Dict = {
  nav: {
    links: { manifesto: 'البيان', capabilities: 'الإمكانات', flow: 'آلية العمل' },
    login: 'تسجيل الدخول',
    start: 'ابدأ مجانًا',
  },
  hero: {
    badge: 'سلة · شوبيفاي · أمازون · نون · زد · ووكومرس',
    title1: 'تحكّم في كل متاجرك',
    title2: 'من كونٍ واحد.',
    subtitle:
      'الطلبات والمخزون والمنتجات من كل قناة — موحّدة ومتزامنة وحيّة في مركز تحكّم واحد.',
    cta1: 'ابدأ تجربتك المجانية',
    cta2: 'اكتشف آلية العمل',
    scroll: 'مرّر للأسفل',
  },
  manifesto: {
    eyebrow: 'المشكلة',
    text:
      'البيع في كل مكان لا يعني إدارته في كل مكان. كل منصّة تتحدّث بلغة مختلفة، وكل لوحة تحكّم تروي نصف الحكاية. يجمع HubbyGlobal هذه الفوضى في مصدرٍ واحد للحقيقة — حيث تنعكس حركةٌ واحدة على كل متاجرك فورًا.',
  },
  showcase: {
    beats: [
      {
        eyebrow: 'اربط',
        title: 'كل متجر بتسجيل دخول واحد.',
        body: 'اربط سلة وزد وشوبيفاي وووكومرس عبر تدفّق OAuth آمن في 30 ثانية — بلا إضافات، بلا برمجة، بلا نسخ ولصق.',
      },
      {
        eyebrow: 'الطلبات',
        title: 'كل الطلبات في مسارٍ واحد.',
        body: 'عالِج وتابِع ونفِّذ الطلبات من كل القنوات في قائمة واحدة سلسة. توقّف عن التنقّل بين لوحات لا تتفق أبدًا.',
      },
      {
        eyebrow: 'المزامنة',
        title: 'علامتك التجارية، متزامنة تمامًا.',
        body: 'عدّل منتجًا أو سعرًا مرّة واحدة وشاهده ينعكس على كل متاجرك لحظة تغييره. مصدرٌ واحد للحقيقة.',
      },
      {
        eyebrow: 'الإيرادات',
        title: 'حوّل انتشارك إلى إيرادات.',
        body: 'اجمع المبيعات من كل منصّة وشاهد أعمالك كاملةً من نظرة واحدة — ثم وسّع ما ينجح.',
      },
    ],
  },
  capabilities: {
    eyebrow: 'الإمكانات',
    heading: 'ست قدرات، واجهة واحدة.',
    cards: [
      {
        title: 'مركز طلبات موحّد',
        desc: 'عالِج وتابِع ونفِّذ الطلبات من كل قنوات البيع في مسار واحد سلس. لا مزيد من التنقّل بين النوافذ.',
      },
      {
        title: 'مزامنة فورية للمخزون',
        desc: 'عدّل المخزون مرّة واحدة وشاهده ينعكس على كل متجر لحظة تغييره.',
      },
      {
        title: 'تحليلات ذكية',
        desc: 'إيرادات واتجاهات وأداء مجمّعة عبر كل المتاجر — نظرة شاملة لأعمالك كلها.',
      },
      {
        title: 'ربط متعدد المتاجر',
        desc: 'اربط عددًا غير محدود من متاجر سلة وزد وشوبيفاي وووكومرس عبر معالج OAuth في 30 ثانية.',
      },
      {
        title: 'صلاحيات حسب الدور',
        desc: 'ادعُ فريقك وحدّد بدقّة من يصل إلى الطلبات أو المنتجات أو الفوترة.',
      },
      {
        title: 'تحكّم من الجوّال',
        desc: 'أدِر عملك بالكامل من جيبك عبر تطبيق أصلي مكافئ بالكامل.',
      },
    ],
  },
  flow: {
    eyebrow: 'آلية العمل',
    heading: 'انطلق في ثلاث خطوات.',
    steps: [
      {
        title: 'اربط متاجرك',
        desc: 'فعّل سلة أو زد أو شوبيفاي أو ووكومرس عبر تدفّق OAuth آمن في ثوانٍ.',
      },
      {
        title: 'حدّد متجرك الرئيسي',
        desc: 'اختر مصدر الحقيقة الذي يقود المخزون الموحّد — نبض شبكتك.',
      },
      {
        title: 'توسّع دون أعباء',
        desc: 'أدِر كل شيء من مركز تحكّم واحد ودع الأعمال اليدوية تختفي.',
      },
    ],
  },
  stats: ['تاجر ينمو معنا', 'منصّات موحّدة', 'دقة المزامنة', 'لربط متجر'],
  cta: {
    eyebrow: 'جاهزون عندما تكون',
    heading: 'تحكّم في كل متاجرك.',
    body: 'انضم إلى التجار الذين يديرون عملياتهم متعددة المتاجر من مركز تحكّم واحد هادئ ومتزامن. تجربة مجانية ١٤ يومًا، دون بطاقة.',
    cta1: 'ابدأ الآن',
    cta2: 'تحدّث مع المبيعات',
  },
  footer: {
    tagline: 'نجعل التجارة متعددة المتاجر بسيطة ومتزامنة وقابلة للتوسّع.',
    columns: [
      { title: 'المنصّة', links: ['الإمكانات', 'التكاملات', 'تطبيق الجوّال', 'الأسعار'] },
      { title: 'الشركة', links: ['من نحن', 'المدوّنة', 'الوظائف', 'تواصل معنا'] },
      { title: 'قانوني', links: ['سياسة الخصوصية', 'شروط الخدمة', 'سياسة الكوكيز'] },
    ],
    copyright: '© ٢٠٢٦ HubbyGlobal. جميع الحقوق محفوظة.',
    social: ['تويتر', 'لينكدإن', 'جِت‑هَب'],
  },
  auth: {
    login: {
      eyebrow: 'أهلًا بعودتك',
      heading: 'تحكّم في كل متاجرك من كونٍ واحد.',
      subheading: 'سجّل الدخول لتكمل من حيث توقفت — الطلبات والمخزون والمنتجات، كلها متزامنة.',
      highlights: [
        'طلبات موحّدة عبر كل قناة',
        'مزامنة فورية للمخزون',
        'لوحة واحدة لكل متاجرك',
      ],
      title: 'تسجيل الدخول',
      subtitle: 'أدخل بياناتك للوصول إلى مركز تحكّمك.',
      email: 'البريد الإلكتروني',
      password: 'كلمة المرور',
      forgot: 'نسيت كلمة المرور؟',
      submit: 'تسجيل الدخول',
      noAccount: 'ليس لديك حساب؟',
      signup: 'أنشئ حسابًا مجانًا',
      invalid: 'بيانات الدخول غير صحيحة',
    },
    register: {
      eyebrow: 'ابدأ مجانًا',
      heading: 'اجمع كل متاجرك تحت سقفٍ واحد.',
      subheading: 'أنشئ حسابك في ثوانٍ واربط سلة وزد وشوبيفاي وووكومرس بمركزٍ واحد متزامن.',
      highlights: [
        'تجربة مجانية ١٤ يومًا، دون بطاقة',
        'اربط متجرًا في ٣٠ ثانية',
        'ادعُ فريقك بصلاحيات حسب الدور',
      ],
      title: 'أنشئ حسابك',
      subtitle: 'انضم إلى التجار الذين ينمون مع HubbyGlobal.',
      name: 'الاسم الكامل',
      namePlaceholder: 'محمد أحمد',
      org: 'اسم المنشأة',
      orgPlaceholder: 'متجري',
      email: 'البريد الإلكتروني',
      password: 'كلمة المرور',
      confirm: 'تأكيد كلمة المرور',
      submit: 'أنشئ الحساب',
      haveAccount: 'لديك حساب بالفعل؟',
      signin: 'تسجيل الدخول',
      termsPre: 'بالتسجيل، فإنك توافق على',
      terms: 'شروط الخدمة',
      and: 'و',
      privacy: 'سياسة الخصوصية',
      generalError: 'حدث خطأ ما. يُرجى المحاولة مرة أخرى.',
    },
    passwords: {
      forgotTitle: 'إعادة تعيين كلمة المرور',
      forgotSubtitle: 'أدخل بريدك الإلكتروني وسنرسل لك رابطًا لاستعادة الدخول.',
      email: 'البريد الإلكتروني',
      sendButton: 'إرسال رابط الاستعادة',
      sent: 'إن كان هناك حساب بهذا البريد، فالرابط في طريقه إليك. تفقّد بريدك.',
      backToLogin: 'العودة لتسجيل الدخول',
      resetTitle: 'اختر كلمة مرور جديدة',
      resetSubtitle: 'اختر كلمة مرور قوية لا تستخدمها في أي مكان آخر.',
      newPassword: 'كلمة المرور الجديدة',
      confirmPassword: 'تأكيد كلمة المرور',
      resetButton: 'إعادة التعيين',
      success: 'تم إعادة تعيين كلمة المرور. يتم تحويلك لتسجيل الدخول…',
      missingToken: 'رابط الاستعادة غير صالح أو منتهي الصلاحية. اطلب رابطًا جديدًا.',
      mismatch: 'كلمتا المرور غير متطابقتين.',
      generalError: 'حدث خطأ ما. يُرجى المحاولة مرة أخرى.',
    },
  },
  langName: 'English',
};

const DICTS: Record<Locale, Dict> = { en, ar };

/* -------------------------------------------------------------------------- */
/* Context                                                                     */
/* -------------------------------------------------------------------------- */

type I18nValue = {
  locale: Locale;
  dir: 'ltr' | 'rtl';
  t: Dict;
  setLocale: (l: Locale) => void;
  toggle: () => void;
};

const I18nContext = createContext<I18nValue | null>(null);

export function I18nProvider({ children }: { children: React.ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>('en');

  // Resolve the initial locale on the client (?lang= overrides storage).
  useEffect(() => {
    const url = new URLSearchParams(window.location.search).get('lang');
    const saved = window.localStorage.getItem('locale');
    const initial: Locale = url === 'ar' || saved === 'ar' ? 'ar' : 'en';
    setLocaleState(initial);
  }, []);

  const dir = locale === 'ar' ? 'rtl' : 'ltr';

  useEffect(() => {
    layout.rtl = locale === 'ar';
  }, [locale]);

  const setLocale = (l: Locale) => {
    setLocaleState(l);
    try {
      window.localStorage.setItem('locale', l);
    } catch {
      /* ignore */
    }
  };

  const value: I18nValue = {
    locale,
    dir,
    t: DICTS[locale],
    setLocale,
    toggle: () => setLocale(locale === 'ar' ? 'en' : 'ar'),
  };

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nValue {
  const ctx = useContext(I18nContext);
  if (!ctx) throw new Error('useI18n must be used within <I18nProvider>');
  return ctx;
}
