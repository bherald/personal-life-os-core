//! Incremental VT decoding for the PLOS Windows frontend. Input is never normalized.
use std::collections::VecDeque;
use std::io;

use crossterm::event::{Event, KeyCode, KeyboardEnhancementFlags};

#[path = "plos_vt_parse.rs"]
mod parse;

#[derive(Debug)]
pub(crate) enum OscColorPayload {
    Unrecognized(String),
}

#[derive(Debug)]
pub(crate) enum InternalEvent {
    Event(Event),
    CursorPosition(u16, u16),
    KeyboardEnhancementFlags(KeyboardEnhancementFlags),
    PrimaryDeviceAttributes,
    OscColor { slot: u8, payload: OscColorPayload },
}

#[derive(Default)]
pub(crate) struct Decoder {
    pending: Vec<u8>,
    ready: VecDeque<Event>,
}

impl Decoder {
    pub(crate) fn feed(&mut self, bytes: &[u8]) -> io::Result<()> {
        for byte in bytes {
            self.pending.push(*byte);
            // Keep incomplete UTF-8 and complete paste payloads across all read boundaries.
            // A lone Escape is resolved by the reader's short idle deadline, never by chunk size.
            match parse::parse_event(&self.pending, true)? {
                Some(InternalEvent::Event(event)) => {
                    self.ready.push_back(event);
                    self.pending.clear();
                }
                Some(_) => self.pending.clear(), // Terminal query response, not submitted text.
                None => {}
            }
        }
        Ok(())
    }

    pub(crate) fn finish_escape(&mut self) {
        if self.pending == b"\x1b" {
            self.pending.clear();
            self.ready.push_back(Event::Key(KeyCode::Esc.into()));
        }
    }

    pub(crate) fn next(&mut self) -> Option<Event> {
        self.ready.pop_front()
    }

    pub(crate) fn pending_bytes(&self) -> &[u8] {
        &self.pending
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crossterm::event::KeyModifiers;

    #[test]
    fn paste_retains_unicode_cr_lf_tabs_nul_and_spaces_at_every_split() {
        let original = "  exact\r\nJosé\t\n\r😀\0  ";
        let frame = format!("\x1b[200~{original}\x1b[201~").into_bytes();
        for split in 0..=frame.len() {
            let mut decoder = Decoder::default();
            decoder.feed(&frame[..split]).unwrap();
            decoder.feed(&frame[split..]).unwrap();
            assert_eq!(decoder.next(), Some(Event::Paste(original.to_string())));
            assert_eq!(decoder.next(), None);
            assert!(decoder.pending_bytes().is_empty());
        }
    }

    #[test]
    fn large_paste_and_following_enter_are_separate_exact_events() {
        let original = "José\r\n\t full original 😀  ".repeat(12000);
        let frame = format!("\x1b[200~{original}\x1b[201~\r");
        let mut decoder = Decoder::default();
        for chunk in frame.as_bytes().chunks(1024) {
            decoder.feed(chunk).unwrap();
        }
        assert_eq!(decoder.next(), Some(Event::Paste(original)));
        assert_eq!(decoder.next(), Some(Event::Key(KeyCode::Enter.into())));
        assert_eq!(decoder.next(), None);
    }

    #[test]
    fn invalid_utf8_is_refused_with_the_original_buffer_retained() {
        let original = b"\x1b[200~original\xff\x1b[201~";
        let mut decoder = Decoder::default();
        assert!(decoder.feed(original).is_err());
        assert_eq!(decoder.pending_bytes(), original);
        assert_eq!(decoder.next(), None);
    }

    #[test]
    fn partial_paste_is_never_flushed_as_keys() {
        let original = b"\x1b[200~  original\r\n\t";
        let mut decoder = Decoder::default();
        decoder.feed(original).unwrap();
        decoder.finish_escape();
        assert_eq!(decoder.pending_bytes(), original);
        assert_eq!(decoder.next(), None);
    }

    #[test]
    fn ordinary_navigation_enter_and_escape_keep_key_semantics() {
        let mut decoder = Decoder::default();
        decoder.feed(b"/help\r\x1b[A\x1b").unwrap();
        for ch in "/help".chars() {
            assert_eq!(decoder.next(), Some(Event::Key(KeyCode::Char(ch).into())));
        }
        assert_eq!(decoder.next(), Some(Event::Key(KeyCode::Enter.into())));
        assert_eq!(decoder.next(), Some(Event::Key(KeyCode::Up.into())));
        assert_eq!(decoder.next(), None);
        decoder.finish_escape();
        assert_eq!(decoder.next(), Some(Event::Key(KeyCode::Esc.into())));
        decoder.feed(b"\x03").unwrap();
        assert_eq!(
            decoder.next(),
            Some(Event::Key(crossterm::event::KeyEvent::new(
                KeyCode::Char('c'),
                KeyModifiers::CONTROL,
            )))
        );
    }
}
