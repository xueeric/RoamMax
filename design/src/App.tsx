import React, { useState, useEffect, useRef } from 'react';
import { Sparkles, Calendar, HelpCircle, Info, Star, ChevronDown, CheckCircle, ShieldCheck, Mail, ArrowRight } from 'lucide-react';
import Navbar from './components/Navbar';
import BookingForm from './components/BookingForm';
import BookingList from './components/BookingList';
import { FAQS } from './data/rentalData';
import { BookingRequest } from './types';

export default function App() {
  const [selectedKitId, setSelectedKitId] = useState('standard-kit');
  const [bookings, setBookings] = useState<BookingRequest[]>([]);
  const [faqSearch, setFaqSearch] = useState('');
  const [activeFaqCategory, setActiveFaqCategory] = useState<'all' | 'rental' | 'setup' | 'billing'>('all');
  const [expandedFaqId, setExpandedFaqId] = useState<string | null>(null);

  // Load bookings from LocalStorage on mount with seed data
  useEffect(() => {
    const stored = localStorage.getItem('starlink_rentals_bookings_v2');
    if (stored) {
      try {
        setBookings(JSON.parse(stored));
      } catch (e) {
        console.error('Error parsing stored bookings:', e);
      }
    } else {
      // Seed initial reservations so calendar shows some dates are pre-booked out-of-the-box!
      const initialMockBookings: BookingRequest[] = [
        {
          id: 'ob-928401',
          fullName: 'Alexander Wright',
          email: 'alex@expeditions.co',
          phone: '(415) 882-9402',
          kitId: 'standard-kit',
          startDate: '2026-05-27',
          endDate: '2026-05-30',
          shippingAddress: '782 Pine Alpine Road',
          city: 'Truckee',
          state: 'CA',
          zipCode: '96161',
          totalPrice: 205,
          depositAmount: 150,
          status: 'confirmed',
          createdAt: '2026-05-20T10:00:00Z'
        },
        {
          id: 'ob-103554',
          fullName: 'Emily Thorne',
          email: 'emily@marineops.net',
          phone: '(305) 555-1200',
          kitId: 'flat-high-performance',
          startDate: '2026-06-11',
          endDate: '2026-06-15',
          shippingAddress: 'Slip 14, Coconut Grove Yacht Marina',
          city: 'Miami',
          state: 'FL',
          zipCode: '33133',
          totalPrice: 524,
          depositAmount: 350,
          status: 'dispatched',
          createdAt: '2026-05-22T14:30:00Z'
        }
      ];
      setBookings(initialMockBookings);
      localStorage.setItem('starlink_rentals_bookings_v2', JSON.stringify(initialMockBookings));
    }
  }, []);

  const saveBookings = (newBookings: BookingRequest[]) => {
    setBookings(newBookings);
    localStorage.setItem('starlink_rentals_bookings_v2', JSON.stringify(newBookings));
  };

  const handleBookingSubmitted = (newRequest: BookingRequest) => {
    const updated = [newRequest, ...bookings];
    saveBookings(updated);
    
    // Scroll to dashboard lists
    setTimeout(() => {
      const dbSection = document.getElementById('dashboard-section-anchor');
      if (dbSection) {
        dbSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }, 400);
  };

  const handleCancelBooking = (bookingId: string) => {
    const updated = bookings.filter(b => b.id !== bookingId);
    saveBookings(updated);
  };

  const scrollToSection = (sectionId: string) => {
    const el = document.getElementById(sectionId);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  };

  // Filter FAQs
  const filteredFaqs = FAQS.filter(faq => {
    const matchesCategory = activeFaqCategory === 'all' || faq.category === activeFaqCategory;
    const matchesSearch = faq.question.toLowerCase().includes(faqSearch.toLowerCase()) || 
                          faq.answer.toLowerCase().includes(faqSearch.toLowerCase());
    return matchesCategory && matchesSearch;
  });

  return (
    <div className="min-h-screen bg-[#f9fafb] text-gray-950 flex flex-col font-sans selection:bg-black selection:text-white" id="app-root">
      
      {/* Visual Navigation Banner */}
      <Navbar 
        pendingCount={bookings.filter(b => b.status === 'pending').length}
        onScrollToSection={scrollToSection}
        onOpenMyBookings={() => scrollToSection('dashboard-section-anchor')}
      />

      {/* Main Container */}
      <main className="flex-grow">
        
        {/* HERO SECTION - Refined typography and structural rhythm */}
        <section className="relative px-4 pt-16 pb-12 sm:px-6 lg:px-8 text-center" id="hero-section">
          <div className="mx-auto max-w-4xl">
            <div className="inline-flex items-center space-x-2 bg-black text-white text-[10px] font-bold uppercase tracking-widest px-3.5 py-1.5 rounded-full mb-6">
              <Sparkles className="h-3.5 w-3.5" />
              <span>Real-Time Date Availability Range Active</span>
            </div>
            
            <h1 className="font-display text-4xl font-extrabold tracking-tight text-gray-950 sm:text-6xl max-w-3xl mx-auto leading-[1.1] sm:leading-[1.05]">
              Starlink Hardware Rental.<br />
              <span className="text-gray-500 font-medium">Selected. Scheduled. Shipped.</span>
            </h1>
            
            <p className="mt-5 text-sm sm:text-base text-gray-505 max-w-2xl mx-auto leading-relaxed">
              No long-term commitments or activation fees. Choose an orbital terminal from our certified kit database, highlight consecutive available travel slots on the calendar, and checkout. We dispatch in ruggedized cases with prepaid return courier labels.
            </p>

            <div className="mt-8 flex justify-center">
              <button
                onClick={() => scrollToSection('booking-portal')}
                className="rounded-xl bg-black px-8 py-3.5 text-xs font-bold text-white shadow-sm hover:bg-gray-800 transition active:scale-95 flex items-center space-x-2 cursor-pointer font-sans"
              >
                <span>Open Interactive Booking Portal</span>
                <ArrowRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        </section>

        {/* PRIMARY BOOKING SYSTEM AREA - Combines Selection, Calendar & Logistics Form */}
        <section className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8" id="booking-portal">
          <div className="grid gap-8 lg:grid-cols-12 items-start">
            
            {/* Left Col: Complete Booking Portal Form (9 Columns) */}
            <div className="lg:col-span-8">
              <BookingForm 
                selectedKitId={selectedKitId}
                onSelectKitId={setSelectedKitId}
                onBookingSubmitted={handleBookingSubmitted}
                bookings={bookings}
              />
            </div>

            {/* Right Col: Active bookings & Core Disclosures (4 Columns) */}
            <div className="lg:col-span-4 lg:sticky lg:top-24 space-y-6" id="dashboard-section-anchor">
              
              {/* Core dispatch guidelines bubble */}
              <div className="rounded-2xl border border-gray-150 bg-white p-5 shadow-xs text-xs space-y-3.5">
                <span className="font-mono text-[9px] uppercase tracking-wider text-gray-400 font-bold block mb-1">
                  PRE-ACTIVATED ORBITAL CODES
                </span>
                <p className="text-gray-600 leading-relaxed font-sans">
                  Each terminal is registered to active Roaming data plans with high-priority cell capacity. Our shipping coordinator coordinates deliveries with FedEx Ground so units arrive 1 day before your selected start date.
                </p>
                <div className="pt-2.5 border-t border-gray-100 flex items-center justify-between text-[11px] font-mono text-gray-505">
                  <span>Activation Surcharges:</span>
                  <span className="text-emerald-700 font-bold">$0.00 (Waived)</span>
                </div>
              </div>

              {/* Dynamic Live Listings Tracker */}
              <BookingList 
                bookings={bookings}
                onCancelBooking={handleCancelBooking}
                onScrollToForm={() => scrollToSection('booking-portal')}
              />

              {/* Storage State telemetry block */}
              <div className="rounded-2xl border border-gray-150 bg-white p-5 text-xs text-gray-500 flex items-start space-x-3 shadow-xs">
                <Info className="h-4.5 w-4.5 text-black shrink-0 mt-0.5" />
                <div className="leading-relaxed">
                  <p className="font-bold text-gray-900 font-mono text-[9px] uppercase">Telemetry Sync</p>
                  <p className="mt-0.5 text-gray-550 leading-relaxed">
                    Reservations write to your browser's <code className="bg-gray-100 px-1 py-0.5 rounded font-mono text-[10px] text-black">localStorage</code> cache. Try booking a date range to watch those calendar cells dynamically lock out from future checkouts.
                  </p>
                </div>
              </div>

            </div>

          </div>
        </section>

        {/* FREQUENTLY ASKED QUESTIONS CONTAINER */}
        <section className="mx-auto max-w-4xl px-4 py-16 sm:px-6 lg:px-8 border-t border-gray-150 mt-12" id="faq-section">
          <div className="text-center mb-10">
            <span className="font-mono text-[10px] uppercase tracking-widest text-gray-400 font-bold">FAQ OPERATIONS</span>
            <h2 className="font-display text-3xl font-bold tracking-tight text-gray-950 mt-2" id="faq-heading">
              Frequently Asked Questions
            </h2>
            <p className="mt-2 text-xs text-gray-500 max-w-md mx-auto">
              Disclosures concerning delivery transit, terminal alignments, deposit return triggers, and satellite coverage.
            </p>
          </div>

          {/* FAQS category filters & Search tools */}
          <div className="flex flex-col md:flex-row gap-3 items-center justify-between mb-6">
            {/* Category tabs */}
            <div className="flex items-center gap-1 p-1 bg-gray-100 rounded-xl w-full md:w-auto font-mono text-[10px] uppercase font-bold text-gray-500 overflow-x-auto">
              {(['all', 'rental', 'setup', 'billing'] as const).map((cat) => (
                <button
                  key={cat}
                  onClick={() => setActiveFaqCategory(cat)}
                  className={`px-3 py-1.5 rounded-lg tracking-wide transition shrink-0 cursor-pointer ${
                    activeFaqCategory === cat
                      ? 'bg-white text-black shadow-xs font-bold'
                      : 'hover:text-black hover:bg-gray-50'
                  }`}
                >
                  {cat}
                </button>
              ))}
            </div>

            {/* In-view search box */}
            <div className="relative w-full md:w-64">
              <input
                type="text"
                placeholder="Search database FAQs..."
                value={faqSearch}
                onChange={(e) => setFaqSearch(e.target.value)}
                className="w-full text-xs text-gray-900 placeholder-gray-450 bg-white border border-gray-200 rounded-xl py-2 pl-3.5 pr-8 outline-none focus:border-black"
              />
              <HelpCircle className="absolute right-2.5 top-2.5 h-3.5 w-3.5 text-gray-405 pointer-events-none" />
            </div>
          </div>

          {/* FAQ Accordions list */}
          <div className="space-y-3" id="faq-accordions">
            {filteredFaqs.length === 0 ? (
              <p className="text-center text-xs text-gray-400 py-6 font-mono border border-dashed border-gray-150 rounded-xl bg-white">
                No matching database Q&amp;As found. Refine your search string.
              </p>
            ) : (
              filteredFaqs.map((faq) => {
                const isOpen = expandedFaqId === faq.id;
                return (
                  <div 
                    key={faq.id} 
                    className="rounded-xl border border-gray-150 bg-white transition overflow-hidden"
                    id={`faq-item-${faq.id}`}
                  >
                    <button
                      onClick={() => setExpandedFaqId(isOpen ? null : faq.id)}
                      className="w-full flex justify-between items-center text-left p-4 sm:p-5 text-xs sm:text-sm font-semibold text-gray-905 hover:bg-gray-50/40 cursor-pointer"
                    >
                      <span className="font-sans font-semibold text-gray-900">{faq.question}</span>
                      <span className="text-gray-400 font-mono text-[10px] font-bold shrink-0 ml-4 bg-gray-50 border border-gray-150 w-5 h-5 rounded-full flex items-center justify-center">
                        {isOpen ? '—' : '+'}
                      </span>
                    </button>
                    {isOpen && (
                      <div className="px-4 sm:px-5 pb-5 pt-1 text-xs text-gray-500 leading-relaxed font-sans border-t border-gray-50 font-normal">
                        {faq.answer}
                      </div>
                    )}
                  </div>
                );
              })
            )}
          </div>
        </section>

      </main>

      {/* FOOTER */}
      <footer className="bg-white border-t border-gray-100 py-12 px-4 sm:px-6 lg:px-8 text-center text-xs text-gray-400">
        <div className="mx-auto max-w-7xl flex flex-col sm:flex-row justify-between items-center gap-4">
          <div className="flex items-center space-x-2">
            <div className="w-6 h-6 bg-black rounded-full flex items-center justify-center">
              <div className="w-3 h-[1px] bg-white rotate-45"></div>
            </div>
            <span className="font-display font-extrabold tracking-tighter text-black uppercase">Starlink Rent</span>
          </div>
          
          <p className="font-sans leading-relaxed text-center sm:text-right text-[11px] text-gray-400">
            © {new Date().getFullYear()} Starlink Rent. High-priority LEO orbital allocation simulator. <br />
            This portal persists values fully locally using reactive storage to bypass loading overheads.
          </p>
        </div>
      </footer>

    </div>
  );
}
