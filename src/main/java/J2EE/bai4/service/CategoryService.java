package J2EE.bai4.service;

import J2EE.bai4.model.Category;
import org.springframework.stereotype.Service;
import java.util.List;
import java.util.ArrayList;

@Service
public class CategoryService {
    private List<Category> listCategory = new ArrayList<>();

    public CategoryService() {
        // Mock data giả lập
        listCategory.add(new Category(1, "Điện thoại"));
        listCategory.add(new Category(2, "Laptop"));
        listCategory.add(new Category(3, "Phụ kiện"));
    }

    public List<Category> getAll() {
        return listCategory;
    }

    public Category get(int id) {
        return listCategory.stream().filter(c -> c.getId() == id).findFirst().orElse(null);
    }
}