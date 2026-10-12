//! Windows VT input for PLOS. The Win32 key-event path cannot retain pasted CR/LF/tab bytes.
use std::io;
use std::os::windows::io::AsRawHandle;
use std::pin::Pin;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::task::{Context, Poll};
use std::thread::{self, JoinHandle};
use std::time::Duration;

use crossterm::event::Event;
use tokio::sync::mpsc::{UnboundedReceiver, UnboundedSender, unbounded_channel};
use windows_sys::Win32::Foundation::{HANDLE, INVALID_HANDLE_VALUE, WAIT_OBJECT_0, WAIT_TIMEOUT};
use windows_sys::Win32::Storage::FileSystem::ReadFile;
use windows_sys::Win32::System::Console::{
    CONSOLE_SCREEN_BUFFER_INFO, GetConsoleCP, GetConsoleMode, GetConsoleScreenBufferInfo,
    GetStdHandle, STD_INPUT_HANDLE, STD_OUTPUT_HANDLE, SetConsoleCP, SetConsoleMode,
};
use windows_sys::Win32::System::IO::CancelSynchronousIo;
use windows_sys::Win32::System::Threading::WaitForSingleObject;

use super::event_stream::{EventResult, EventSource};
use super::plos_vt_decoder::Decoder;

const VT_INPUT: u32 = 0x0200;

struct ConsoleMode {
    input: HANDLE,
    mode: u32,
    code_page: u32,
}

impl ConsoleMode {
    fn enter() -> io::Result<Self> {
        let input = unsafe { GetStdHandle(STD_INPUT_HANDLE) };
        let mut mode = 0;
        if input == 0
            || input == INVALID_HANDLE_VALUE
            || unsafe { GetConsoleMode(input, &mut mode) } == 0
        {
            return Err(io::Error::last_os_error());
        }
        let guard = Self {
            input,
            mode,
            code_page: unsafe { GetConsoleCP() },
        };
        // No processed, echoed or line-buffered input; UTF-8 avoids OEM-code-page substitution.
        if unsafe { SetConsoleMode(input, (mode & !7) | VT_INPUT) } == 0
            || unsafe { SetConsoleCP(65001) } == 0
        {
            return Err(io::Error::last_os_error());
        }
        Ok(guard)
    }
}

impl Drop for ConsoleMode {
    fn drop(&mut self) {
        unsafe {
            SetConsoleCP(self.code_page);
            SetConsoleMode(self.input, self.mode);
        }
    }
}

pub struct WindowsVtEventSource {
    events: UnboundedReceiver<EventResult>,
    stop: Arc<AtomicBool>,
    reader: Option<JoinHandle<()>>,
}

impl Default for WindowsVtEventSource {
    fn default() -> Self {
        let (send, events) = unbounded_channel();
        let stop = Arc::new(AtomicBool::new(false));
        let reader_stop = stop.clone();
        let reader = thread::spawn(move || {
            if let Err(error) = read_events(&send, &reader_stop) {
                if !reader_stop.load(Ordering::Acquire) {
                    let _ = send.send(Err(error));
                }
            }
        });
        Self {
            events,
            stop,
            reader: Some(reader),
        }
    }
}

impl EventSource for WindowsVtEventSource {
    fn poll_next(self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<Option<EventResult>> {
        self.get_mut().events.poll_recv(cx)
    }
}

impl Drop for WindowsVtEventSource {
    fn drop(&mut self) {
        self.stop.store(true, Ordering::Release);
        if let Some(reader) = self.reader.take() {
            // Repeat cancellation to cover a read beginning between the stop check and ReadFile.
            // Join before a replacement reader starts; an old reader must never consume new input.
            while !reader.is_finished() {
                unsafe {
                    CancelSynchronousIo(reader.as_raw_handle() as HANDLE);
                }
                thread::sleep(Duration::from_millis(5));
            }
            let _ = reader.join();
        }
    }
}

fn read_events(send: &UnboundedSender<EventResult>, stop: &AtomicBool) -> io::Result<()> {
    let console = ConsoleMode::enter()?;
    let mut decoder = Decoder::default();
    let mut buffer = [0u8; 4096];
    let mut previous_size = None;
    while !stop.load(Ordering::Acquire) {
        let mut info: CONSOLE_SCREEN_BUFFER_INFO = unsafe { std::mem::zeroed() };
        if unsafe { GetConsoleScreenBufferInfo(GetStdHandle(STD_OUTPUT_HANDLE), &mut info) } != 0 {
            let size = (
                (info.srWindow.Right - info.srWindow.Left + 1) as u16,
                (info.srWindow.Bottom - info.srWindow.Top + 1) as u16,
            );
            if previous_size != Some(size) {
                previous_size = Some(size);
                if send.send(Ok(Event::Resize(size.0, size.1))).is_err() {
                    return Ok(());
                }
            }
        }
        match unsafe { WaitForSingleObject(console.input, 20) } {
            WAIT_TIMEOUT => decoder.finish_escape(),
            WAIT_OBJECT_0 => {
                if stop.load(Ordering::Acquire) {
                    break;
                }
                let mut count = 0;
                if unsafe {
                    ReadFile(
                        console.input,
                        buffer.as_mut_ptr(),
                        buffer.len() as u32,
                        &mut count,
                        std::ptr::null_mut(),
                    )
                } == 0
                {
                    return Err(io::Error::last_os_error());
                }
                if count == 0 {
                    return Err(io::Error::new(
                        io::ErrorKind::UnexpectedEof,
                        "Windows terminal input ended.",
                    ));
                }
                decoder.feed(&buffer[..count as usize])?;
            }
            _ => return Err(io::Error::last_os_error()),
        }
        while let Some(event) = decoder.next() {
            if send.send(Ok(event)).is_err() {
                return Ok(());
            }
        }
    }
    Ok(())
}
