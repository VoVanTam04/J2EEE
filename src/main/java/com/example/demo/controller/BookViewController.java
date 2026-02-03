package com.example.demo.controller;

import com.example.demo.model.Book;
import com.example.demo.service.BookService;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.servlet.mvc.support.RedirectAttributes;

@Controller
@RequestMapping("/books")
public class BookViewController {

    @Autowired
    private BookService bookService;

    @GetMapping
    public String listBooks(Model model) {
        model.addAttribute("books", bookService.getAllBooks());
        return "books";
    }

    @GetMapping("/add")
    public String showAddForm(Model model) {
        model.addAttribute("book", new Book());
        return "add-book";
    }

    @PostMapping("/add")
    public String addBook(@ModelAttribute Book book, RedirectAttributes redirectAttributes) {
        bookService.addBook(book);
        redirectAttributes.addFlashAttribute("message", "Sách đã được thêm thành công!");
        return "redirect:/books";
    }

    @GetMapping("/edit/{id}")
    public String showEditForm(@PathVariable int id, Model model) {
        Book book = bookService.getBookById(id);
        if (book == null) {
            return "redirect:/books";
        }
        model.addAttribute("book", book);
        return "edit-book";
    }

    @PostMapping("/edit/{id}")
    public String updateBook(@PathVariable int id, @ModelAttribute Book book, RedirectAttributes redirectAttributes) {
        bookService.updateBook(id, book);
        redirectAttributes.addFlashAttribute("message", "Sách đã được cập nhật thành công!");
        return "redirect:/books";
    }

    @GetMapping("/delete/{id}")
    public String deleteBook(@PathVariable int id, RedirectAttributes redirectAttributes) {
        bookService.deleteBook(id);
        redirectAttributes.addFlashAttribute("message", "Sách đã được xóa thành công!");
        return "redirect:/books";
    }
}
