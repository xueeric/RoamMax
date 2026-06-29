import React from 'react';
import { HelpCircle, FileText, Globe, ArrowRight, Calendar } from 'lucide-react';

interface NavbarProps {
  pendingCount: number;
  onScrollToSection: (sectionId: string) => void;
  onOpenMyBookings: () => void;
}

export default function Navbar({ pendingCount, onScrollToSection, onOpenMyBookings }: NavbarProps) {
  return (
    <header className="sticky top-0 z-40 w-full border-b border-gray-100 bg-white/90 backdrop-blur-md">
      <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        
        {/* Brand Logo - Clean Minimalism */}
         <div 
          onClick={() => onScrollToSection('hero-section')} 
          className="flex cursor-pointer items-center space-x-3 transition hover:opacity-85"
          id="nav-logo"
        >
          <div className="w-8 h-8 bg-black rounded-full flex items-center justify-center">
            <div className="w-4 h-[1.5px] bg-white rotate-45"></div>
          </div>
          <div>
            <div className="flex items-center space-x-1.5">
              <span className="font-display text-sm font-bold tracking-tighter uppercase text-black">Starlink Rent</span>
              <span className="font-mono text-[9px] uppercase tracking-widest bg-gray-100 text-gray-650 px-1.5 py-0.5 rounded font-bold">LEO</span>
            </div>
            <p className="font-mono text-[9px] text-gray-400">Constellation Schedule Grid</p>
          </div>
        </div>

        {/* Navigation Desktop */}
        <nav className="hidden md:flex items-center space-x-8 text-xs font-semibold uppercase tracking-wider text-gray-500 font-mono">
          <button 
            onClick={() => onScrollToSection('booking-portal')} 
            className="flex items-center space-x-1.5 hover:text-black transition cursor-pointer"
            id="nav-link-booking"
          >
            <Calendar className="h-4 w-4 text-gray-400" />
            <span>Booking System</span>
          </button>

          <button 
            onClick={() => onScrollToSection('faq-section')} 
            className="flex items-center space-x-1.5 hover:text-black transition cursor-pointer"
            id="nav-link-faq"
          >
            <HelpCircle className="h-4 w-4 text-gray-400" />
            <span>Terms &amp; FAQ</span>
          </button>
        </nav>

        {/* Navigation Actions */}
        <div className="flex items-center space-x-3">
          <button
            onClick={onOpenMyBookings}
            className="group relative flex items-center space-x-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-xs font-bold text-gray-700 transition hover:border-black hover:bg-gray-50 font-mono"
            id="nav-btn-bookings"
          >
            <FileText className="h-4 w-4 text-gray-400 group-hover:text-black" />
            <span>Active Feed</span>
            
            {pendingCount > 0 ? (
              <span className="absolute -top-1.5 -right-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-black text-[10px] font-bold text-white ring-2 ring-white">
                {pendingCount}
              </span>
            ) : null}
          </button>

          <button
            onClick={() => onScrollToSection('booking-portal')}
            className="hidden sm:inline-flex items-center rounded-xl bg-black px-4 py-2.5 text-xs font-bold text-white transition hover:bg-gray-800 active:scale-95 cursor-pointer font-sans"
            id="nav-btn-rent-now"
          >
            <span>Reserve Slots</span>
            <ArrowRight className="h-3 w-[12px] ml-1.5 shrink-0" />
          </button>
        </div>

      </div>
    </header>
  );
}
