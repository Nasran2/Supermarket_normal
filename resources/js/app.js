import './expense-category.js';
import './register.js';
import {
  createIcons,
  ShoppingBasket,
  LayoutDashboard,
  ScanLine,
  ReceiptText,
  PackageOpen,
  Wallet,
  Package,
  Tags,
  Ruler,
  Truck,
  Contact,
  CircleDollarSign,
  ChartNoAxesCombined,
  Users,
  User,
  Lock,
  PackagePlus,
  FileText,
  ShieldCheck,
  Settings2,
  PanelLeft,
  ChevronRight,
  ArrowRight,
  Bell,
  ChevronDown,
  CircleCheck,
  X,
  CircleAlert,
  ArrowLeft,
  Plus,
  Search,
  Eye,
  Pencil,
  Trash2,
  FolderOpen,
  ArrowUpRight,
  Boxes,
  Check,
  Building2,
  Printer,
  SlidersHorizontal,
  Percent,
  CreditCard,
  TrendingUp,
  ShoppingBag,
  Ban,
  History,
  Banknote,
  QrCode,
  Landmark,
  Info,
  SearchX,
  Download,
  Menu,
} from 'lucide';
const icons = {
  AlertCircle: CircleAlert,
  ShoppingBasket,
  LayoutDashboard,
  ScanLine,
  ReceiptText,
  PackageOpen,
  Wallet,
  Package,
  Tags,
  Ruler,
  Truck,
  Contact,
  CircleDollarSign,
  ChartNoAxesCombined,
  Users,
  User,
  Lock,
  PackagePlus,
  FileText,
  ShieldCheck,
  Settings2,
  PanelLeft,
  ChevronRight,
  ArrowRight,
  Bell,
  ChevronDown,
  CircleCheck,
  X,
  CircleAlert,
  ArrowLeft,
  Plus,
  Search,
  Eye,
  Pencil,
  Trash2,
  FolderOpen,
  ArrowUpRight,
  Boxes,
  Check,
  Building2,
  Printer,
  SlidersHorizontal,
  Percent,
  CreditCard,
  TrendingUp,
  ShoppingBag,
  Ban,
  History,
  Banknote,
  QrCode,
  Landmark,
  Info,
  SearchX,
  Download,
  Menu,
};
window.refreshIcons = () => createIcons({ icons });
window.refreshIcons();
const sidebar = document.getElementById('sidebar'),
  overlay = document.getElementById('nav-overlay'),
  toggle = document.getElementById('nav-toggle');
const posWorkspace = document.body.classList.contains('pos-workspace');
if (!posWorkspace && localStorage.getItem('twinsofte-nav') === 'collapsed')
  document.body.classList.add('nav-collapsed');
const closeNav = () => {
  sidebar?.classList.remove('mobile-open');
  overlay?.classList.remove('visible');
  toggle?.setAttribute('aria-expanded', 'false');
};
toggle?.addEventListener('click', () => {
  if (posWorkspace || innerWidth <= 1024) {
    const open = sidebar.classList.toggle('mobile-open');
    overlay.classList.toggle('visible', open);
    toggle.setAttribute('aria-expanded', String(open));
  } else {
    const collapsed = document.body.classList.toggle('nav-collapsed');
    localStorage.setItem('twinsofte-nav', collapsed ? 'collapsed' : 'expanded');
    toggle.setAttribute('aria-expanded', String(!collapsed));
  }
});
overlay?.addEventListener('click', closeNav);
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeNav();
});
document.addEventListener('click', (e) => {
  const dismiss = e.target.closest('[data-dismiss]');
  dismiss?.closest('.notice')?.remove();
});
document.addEventListener('submit', (e) => {
  const form = e.target;
  if (form.dataset.confirm && !confirm(form.dataset.confirm)) {
    e.preventDefault();
    return;
  }
  const button = e.submitter;
  if (button && !e.defaultPrevented) {
    setTimeout(() => {
      button.disabled = true;
      button.dataset.originalText = button.textContent;
      button.textContent = 'Saving…';
    }, 0);
  }
});

// Opening a group from the desktop icon rail reveals its sublinks.
document.querySelectorAll('.nav-group > summary').forEach((summary) => {
  summary.addEventListener('click', () => {
    if (document.body.classList.contains('nav-collapsed') && innerWidth > 1024 && !posWorkspace) {
      document.body.classList.remove('nav-collapsed');
      localStorage.setItem('twinsofte-nav', 'expanded');
      toggle?.setAttribute('aria-expanded', 'true');
    }
  });
});

import './module-editor.js';
